package main

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/omnitix/go-inventory/internal/config"
	grpcserver "github.com/omnitix/go-inventory/internal/grpc"
	"github.com/omnitix/go-inventory/internal/httpapi"
	"github.com/omnitix/go-inventory/internal/inventory"
	"github.com/omnitix/go-inventory/internal/kafka"
	"github.com/omnitix/go-inventory/internal/logger"
	"github.com/omnitix/go-inventory/internal/redis"
	"go.uber.org/zap"
)

const serviceName = "go-inventory"

func main() {
	// ─── Load config ────────────────────────────────────
	cfg, err := config.Load()
	if err != nil {
		os.Stderr.WriteString("config error: " + err.Error() + "\n")
		os.Exit(1)
	}

	// ─── Init logger ────────────────────────────────────
	log := logger.MustNew(serviceName, cfg.Env, cfg.LogLevel)
	defer func() { _ = log.Sync() }()

	log.Info("OmniTix Inventory Engine starting",
		zap.String("version", "0.1.0"),
		zap.Int("grpc_port", cfg.GRPCPort),
		zap.Int("http_port", cfg.HTTPPort),
		zap.String("redis", cfg.Redis.Addr()),
		zap.Strings("kafka_brokers", cfg.Kafka.Brokers),
		zap.Duration("default_hold_ttl", cfg.DefaultHoldTTL),
	)

	// ─── Signal handling ────────────────────────────────
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		sig := <-sigCh
		log.Info("received signal, initiating shutdown", zap.String("signal", sig.String()))
		cancel()
	}()

	// ─── Connect to Redis ───────────────────────────────
	redisClient := redis.New(&cfg.Redis, log)
	defer func() {
		if err := redisClient.Close(); err != nil {
			log.Warn("error closing Redis client", zap.Error(err))
		}
	}()

	redisCtx, redisCancel := context.WithTimeout(ctx, 30*time.Second)
	if err := redisClient.WaitUntilReady(redisCtx, 30*time.Second); err != nil {
		log.Fatal("redis unavailable", zap.Error(err))
	}
	redisCancel()

	// ─── Load Lua scripts ───────────────────────────────
	scripts := redis.NewScripts()

	// ─── Connect to Kafka ───────────────────────────────
	kafkaProducer, err := kafka.NewProducer(&cfg.Kafka, log, "go-inventory")
	if err != nil {
		log.Fatal("failed to init Kafka producer", zap.Error(err))
	}

	kafkaCtx, kafkaCancel := context.WithTimeout(ctx, 10*time.Second)
	if err := kafkaProducer.WaitUntilReady(kafkaCtx, 10*time.Second); err != nil {
		log.Warn("kafka not ready at boot; producer will retry", zap.Error(err))
	}
	kafkaCancel()

	// ─── Init seat service ──────────────────────────────
	seatSvc := inventory.NewSeatService(
		redisClient,
		scripts,
		kafkaProducer,
		log,
		cfg.DefaultHoldTTL,
		time.Duration(cfg.MaxExtendSeconds)*time.Second,
	)

	// ─── Init GA service ────────────────────────────────
	gaSvc := inventory.NewGaService(redisClient, scripts, kafkaProducer, log, cfg.DefaultHoldTTL)

	// ─── Init best-available service ────────────────────
	baSvc := inventory.NewBestAvailableService(redisClient, scripts, seatSvc, log)

	// ─── Init expiry handler + Redis subscriber ─────────
	expirySvc := inventory.NewExpiryService(redisClient, kafkaProducer, seatSvc, gaSvc, log)

	subscriber := redis.NewSubscriber(redisClient, expirySvc, log)
	if err := subscriber.Start(); err != nil {
		log.Error("failed to start Redis keyspace subscriber — expiry events will NOT be published",
			zap.Error(err),
		)
	} else {
		log.Info("redis keyspace subscriber started")
	}

	// ─── Init gRPC handler and server ───────────────────
	handler := grpcserver.NewInventoryHandler(
		seatSvc,
		gaSvc,
		baSvc,
		redisClient,
		kafkaProducer,
		log,
	)

	grpcSrv, err := grpcserver.NewServer(cfg.GRPCPort, handler, log)
	if err != nil {
		log.Fatal("failed to start gRPC server", zap.Error(err))
	}

	// ─── Start HTTP gateway ─────────────────────────────
	httpSrv := httpapi.New(cfg.HTTPPort, handler, log)
	go func() {
		if err := httpSrv.Start(); err != nil && err != http.ErrServerClosed {
			log.Error("HTTP gateway error", zap.Error(err))
		}
	}()

	// ─── Smoke test hooks (Step 3.4/3.6 only — removed in 3.8) ──
	if cfg.Env == "local" && os.Getenv("INVENTORY_SMOKE_TEST") == "1" {
		runSmokeTest(ctx, seatSvc, log)
		runGaSmokeTest(ctx, seatSvc, gaSvc, log)
		runBestAvailableSmokeTest(ctx, seatSvc, baSvc, log)

		log.Info("smoke mode: waiting for kafka flush...")
		time.Sleep(1 * time.Second)

		subscriber.Stop()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		_ = httpSrv.Stop(shutdownCtx)
		grpcSrv.Stop(2 * time.Second)
		kafkaProducer.Close()

		log.Info("goodbye 👋")
		return
	}

	// ─── Boot complete ──────────────────────────────────
	log.Info("boot sequence complete — waiting for shutdown signal")

	// ─── Wait for shutdown ──────────────────────────────
	<-ctx.Done()

	// ─── Graceful shutdown ──────────────────────────────
	log.Info("draining in-flight requests (10s)...")

	subscriber.Stop()

	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = httpSrv.Stop(shutdownCtx)
	grpcSrv.Stop(8 * time.Second)
	kafkaProducer.Close()

	log.Info("goodbye 👋")
}

// ─── Smoke tests (unchanged from original) ──────────────────

func runSmokeTest(ctx context.Context, svc *inventory.SeatService, log *logger.Logger) {
	log.Info("=== SMOKE TEST START ===")

	occID := "smoke-occurrence-01"
	seatID := "smoke-seat-A1"
	user1 := "user-alice"
	user2 := "user-bob"

	if err := svc.CleanupSmokeTest(ctx, occID); err != nil {
		log.Warn("smoke: cleanup failed (non-fatal)", zap.Error(err))
	}

	hold, err := svc.HoldSeat(ctx, inventory.HoldSeatParams{
		OccurrenceID: occID,
		SeatID:       seatID,
		SeatLabel:    "Orchestra A1",
		Section:      "ORCH",
		Row:          "A",
		SeatNumber:   "1",
		UserID:       user1,
	})
	if err != nil {
		log.Error("smoke: alice hold failed", zap.Error(err))
		return
	}
	log.Info("smoke: alice holds seat", zap.String("token", hold.Token))

	_, err = svc.HoldSeat(ctx, inventory.HoldSeatParams{
		OccurrenceID: occID,
		SeatID:       seatID,
		SeatLabel:    "Orchestra A1",
		Section:      "ORCH",
		Row:          "A",
		SeatNumber:   "1",
		UserID:       user2,
	})
	if err == nil {
		log.Error("smoke: FAIL — bob held a seat alice already holds")
		return
	}
	log.Info("smoke: bob correctly rejected", zap.Error(err))

	newTTL, err := svc.ExtendHold(ctx, inventory.ExtendHoldParams{
		Token:             hold.Token,
		UserID:            user1,
		AdditionalSeconds: 60,
	})
	if err != nil {
		log.Error("smoke: extend failed", zap.Error(err))
		return
	}
	log.Info("smoke: hold extended", zap.Duration("new_ttl", newTTL))

	err = svc.ConfirmHold(ctx, inventory.ConfirmHoldParams{
		Token:   hold.Token,
		UserID:  user1,
		OrderID: "smoke-order-01",
	})
	if err != nil {
		log.Error("smoke: confirm failed", zap.Error(err))
		return
	}
	log.Info("smoke: seat confirmed (sold)")

	_, err = svc.HoldSeat(ctx, inventory.HoldSeatParams{
		OccurrenceID: occID,
		SeatID:       seatID,
		SeatLabel:    "Orchestra A1",
		Section:      "ORCH",
		Row:          "A",
		SeatNumber:   "1",
		UserID:       user2,
	})
	if err == nil {
		log.Error("smoke: FAIL — bob held a sold seat")
		return
	}
	log.Info("smoke: bob rejected (sold)", zap.Error(err))

	log.Info("=== SMOKE TEST PASSED ===")
}

func runGaSmokeTest(ctx context.Context, _ *inventory.SeatService, gaSvc *inventory.GaService, log *logger.Logger) {
	log.Info("=== GA SMOKE TEST START ===")

	occID := "smoke-occurrence-ga-01"
	zoneID := "smoke-zone-pit"
	zoneName := "Standing Pit"
	user1 := "user-alice"
	user2 := "user-bob"
	user3 := "user-carol"

	cleanupGa := func() {
		for _, k := range []string{
			fmt.Sprintf("omnitix:ga:%s:zone:%s:held", occID, zoneID),
			fmt.Sprintf("omnitix:ga:%s:zone:%s:sold", occID, zoneID),
			fmt.Sprintf("omnitix:ga:%s:zone:%s:capacity", occID, zoneID),
		} {
			_ = gaSvc.RawDel(ctx, k)
		}
	}
	cleanupGa()
	defer cleanupGa()

	if err := gaSvc.EnsureCapacityKey(ctx, occID, zoneID, 10); err != nil {
		log.Error("ga smoke: ensure capacity failed", zap.Error(err))
		return
	}

	aliceHold, remaining, err := gaSvc.HoldGaZone(ctx, inventory.HoldGaParams{
		OccurrenceID: occID,
		GaZoneID:     zoneID,
		GaZoneName:   zoneName,
		UserID:       user1,
		Quantity:     4,
	})
	if err != nil {
		log.Error("ga smoke: alice hold failed", zap.Error(err))
		return
	}
	log.Info("ga smoke: alice holds 4", zap.Int("remaining", remaining))

	_, remaining, err = gaSvc.HoldGaZone(ctx, inventory.HoldGaParams{
		OccurrenceID: occID,
		GaZoneID:     zoneID,
		GaZoneName:   zoneName,
		UserID:       user2,
		Quantity:     5,
	})
	if err != nil {
		log.Error("ga smoke: bob hold failed (unexpected)", zap.Error(err))
		return
	}
	log.Info("ga smoke: bob holds 5", zap.Int("remaining", remaining))

	_, _, err = gaSvc.HoldGaZone(ctx, inventory.HoldGaParams{
		OccurrenceID: occID,
		GaZoneID:     zoneID,
		GaZoneName:   zoneName,
		UserID:       user3,
		Quantity:     3,
	})
	if err == nil {
		log.Error("ga smoke: FAIL — carol held 3 when only 1 available")
		return
	}
	log.Info("ga smoke: carol correctly rejected", zap.Error(err))

	capacity, held, sold, available, err := gaSvc.GetGaAvailability(ctx, occID, zoneID)
	if err != nil {
		log.Error("ga smoke: availability failed", zap.Error(err))
		return
	}
	log.Info("ga smoke: availability",
		zap.Int("capacity", capacity),
		zap.Int("held", held),
		zap.Int("sold", sold),
		zap.Int("available", available),
	)

	if err := gaSvc.ConfirmGaZone(ctx, inventory.ConfirmGaParams{
		Token:   aliceHold.Token,
		UserID:  user1,
		OrderID: "smoke-order-ga-01",
	}); err != nil {
		log.Error("ga smoke: alice confirm failed", zap.Error(err))
		return
	}
	log.Info("ga smoke: alice confirmed 4 units sold")

	capacity, held, sold, available, _ = gaSvc.GetGaAvailability(ctx, occID, zoneID)
	log.Info("ga smoke: post-confirm availability",
		zap.Int("capacity", capacity),
		zap.Int("held", held),
		zap.Int("sold", sold),
		zap.Int("available", available),
	)

	log.Info("=== GA SMOKE TEST PASSED ===")
}

func runBestAvailableSmokeTest(
	ctx context.Context,
	seatSvc *inventory.SeatService,
	baSvc *inventory.BestAvailableService,
	log *logger.Logger,
) {
	log.Info("=== BEST-AVAILABLE SMOKE TEST START ===")

	occID := "smoke-occurrence-ba-01"
	section := "ORCH"
	user1 := "user-alice"
	user2 := "user-bob"

	if err := seatSvc.CleanupSmokeTest(ctx, occID); err != nil {
		log.Warn("ba smoke: cleanup failed", zap.Error(err))
	}
	_ = seatSvc.RawDel(ctx, fmt.Sprintf("omnitix:layout:%s", occID))

	layout := &inventory.LayoutCache{
		OccurrenceID: occID,
		Sections: map[string][]inventory.SeatInfo{
			section: {
				{SeatID: "ba-A1", SeatLabel: "Orchestra A1", Section: "ORCH", Row: "A", SeatNumber: 1, X: 100, Y: 200},
				{SeatID: "ba-A2", SeatLabel: "Orchestra A2", Section: "ORCH", Row: "A", SeatNumber: 2, X: 120, Y: 200},
				{SeatID: "ba-A3", SeatLabel: "Orchestra A3", Section: "ORCH", Row: "A", SeatNumber: 3, X: 140, Y: 200},
				{SeatID: "ba-A4", SeatLabel: "Orchestra A4", Section: "ORCH", Row: "A", SeatNumber: 4, X: 160, Y: 200},
				{SeatID: "ba-A5", SeatLabel: "Orchestra A5", Section: "ORCH", Row: "A", SeatNumber: 5, X: 180, Y: 200},
				{SeatID: "ba-B1", SeatLabel: "Orchestra B1", Section: "ORCH", Row: "B", SeatNumber: 1, X: 100, Y: 240},
				{SeatID: "ba-B2", SeatLabel: "Orchestra B2", Section: "ORCH", Row: "B", SeatNumber: 2, X: 120, Y: 240},
				{SeatID: "ba-B3", SeatLabel: "Orchestra B3", Section: "ORCH", Row: "B", SeatNumber: 3, X: 140, Y: 240},
				{SeatID: "ba-B4", SeatLabel: "Orchestra B4", Section: "ORCH", Row: "B", SeatNumber: 4, X: 160, Y: 240},
				{SeatID: "ba-B5", SeatLabel: "Orchestra B5", Section: "ORCH", Row: "B", SeatNumber: 5, X: 180, Y: 240},
			},
		},
	}
	if err := baSvc.CacheLayout(ctx, layout); err != nil {
		log.Error("ba smoke: cache layout failed", zap.Error(err))
		return
	}

	result, err := baSvc.FindBestAvailable(ctx, inventory.BestAvailableParams{
		OccurrenceID: occID,
		SectionCode:  section,
		Quantity:     2,
		UserID:       user1,
	})
	if err != nil {
		log.Error("ba smoke: test1 failed", zap.Error(err))
		return
	}
	log.Info("ba smoke: test1 (2 best)",
		zap.String("section", result.Section),
		zap.String("row", result.Row),
		zap.Int("count", len(result.Holds)),
	)

	if err := seatSvc.CleanupSmokeTest(ctx, occID); err != nil {
		log.Warn("ba smoke: cleanup failed", zap.Error(err))
	}

	for _, seatID := range []string{"ba-A1", "ba-A2", "ba-A4", "ba-A5"} {
		_, err := seatSvc.HoldSeat(ctx, inventory.HoldSeatParams{
			OccurrenceID: occID,
			SeatID:       seatID,
			SeatLabel:    "Orchestra " + seatID,
			Section:      "ORCH",
			Row:          "A",
			SeatNumber:   seatID[4:5],
			UserID:       user1,
		})
		if err != nil {
			log.Error("ba smoke: pre-hold failed", zap.String("seat", seatID), zap.Error(err))
			return
		}
	}

	result, err = baSvc.FindBestAvailable(ctx, inventory.BestAvailableParams{
		OccurrenceID: occID,
		SectionCode:  section,
		Quantity:     2,
		UserID:       user2,
	})
	if err != nil {
		log.Error("ba smoke: test2 failed", zap.Error(err))
		return
	}
	if result.Row != "B" {
		log.Error("ba smoke: FAIL — expected row B, got " + result.Row)
		return
	}
	log.Info("ba smoke: test2 (avoided orphan in A)",
		zap.String("row", result.Row),
		zap.Int("count", len(result.Holds)),
	)

	_ = seatSvc.CleanupSmokeTest(ctx, occID)
	_ = seatSvc.RawDel(ctx, fmt.Sprintf("omnitix:layout:%s", occID))

	log.Info("=== BEST-AVAILABLE SMOKE TEST PASSED ===")
}