package inventory

import (
	"context"
	"fmt"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/omnitix/go-inventory/internal/config"
	"github.com/omnitix/go-inventory/internal/logger"
	omnitixredis "github.com/omnitix/go-inventory/internal/redis"
	goredis "github.com/redis/go-redis/v9"
)

// ─── Test harness ────────────────────────────────────────────

type testEnv struct {
	mr      *miniredis.Miniredis
	rdb     *omnitixredis.Client
	scripts *omnitixredis.Scripts
	log     *logger.Logger
	seatSvc *SeatService
	gaSvc   *GaService
	baSvc   *BestAvailableService
	pub     *noopPublisher
}

// noopPublisher satisfies Publisher without touching Kafka.
type noopPublisher struct{ calls []string }

func (n *noopPublisher) Publish(
	_ context.Context,
	topicSuffix, _, _ string,
	_ any,
) error {
	n.calls = append(n.calls, topicSuffix)
	return nil
}

func newTestEnv(t *testing.T) *testEnv {
	t.Helper()

	mr, err := miniredis.Run()
	if err != nil {
		t.Fatalf("miniredis: %v", err)
	}
	t.Cleanup(mr.Close)

	client := goredis.NewClient(&goredis.Options{Addr: mr.Addr()})
	t.Cleanup(func() { _ = client.Close() })

	cfg := &config.RedisConfig{Host: mr.Host(), Port: mr.Port()}
	log := mustLogger(t)
	rdb := omnitixredis.NewWithClient(client, cfg, log)

	scripts := omnitixredis.NewScripts()
	pub := &noopPublisher{}

	seatSvc := NewSeatService(rdb, scripts, pub, log, 60*time.Second, 5*time.Minute)
	gaSvc := NewGaService(rdb, scripts, pub, log, 60*time.Second)
	baSvc := NewBestAvailableService(rdb, scripts, seatSvc, log)

	return &testEnv{
		mr:      mr,
		rdb:     rdb,
		scripts: scripts,
		log:     log,
		seatSvc: seatSvc,
		gaSvc:   gaSvc,
		baSvc:   baSvc,
		pub:     pub,
	}
}

func mustLogger(t *testing.T) *logger.Logger {
	t.Helper()
	return logger.MustNew("test", "local", "error")
}

// ─── Seat hold tests ─────────────────────────────────────────

func TestHoldSeat_SucceedsWhenAvailable(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	hold, err := env.seatSvc.HoldSeat(ctx, HoldSeatParams{
		OccurrenceID: "occ1",
		SeatID:       "A1",
		SeatLabel:    "Orchestra A1",
		Section:      "ORCH",
		Row:          "A",
		SeatNumber:   "1",
		UserID:       "alice",
	})
	if err != nil {
		t.Fatalf("HoldSeat: %v", err)
	}
	if hold == nil || hold.Token == "" {
		t.Fatal("expected non-empty token")
	}

	members, _ := env.rdb.SMembers(ctx, omnitixredis.HeldSeatsSetKey("occ1")).Result()
	if len(members) != 1 || members[0] != "A1" {
		t.Fatalf("expected held set to contain A1, got %v", members)
	}
}

func TestHoldSeat_RejectsWhenAlreadyHeld(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	_, err := env.seatSvc.HoldSeat(ctx, HoldSeatParams{
		OccurrenceID: "occ1",
		SeatID:       "A1",
		UserID:       "alice",
	})
	if err != nil {
		t.Fatalf("first hold: %v", err)
	}

	_, err = env.seatSvc.HoldSeat(ctx, HoldSeatParams{
		OccurrenceID: "occ1",
		SeatID:       "A1",
		UserID:       "bob",
	})
	if err == nil {
		t.Fatal("expected second hold to fail")
	}
	invErr, ok := err.(*Error)
	if !ok || invErr.Code != ErrCodeSeatAlreadyHeld {
		t.Fatalf("expected ErrCodeSeatAlreadyHeld, got %v", err)
	}
}

func TestHoldSeat_RejectsWhenSold(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	soldKey := omnitixredis.SoldSeatsSetKey("occ1")
	if err := env.rdb.SAdd(ctx, soldKey, "A1").Err(); err != nil {
		t.Fatalf("seed sold: %v", err)
	}

	_, err := env.seatSvc.HoldSeat(ctx, HoldSeatParams{
		OccurrenceID: "occ1",
		SeatID:       "A1",
		UserID:       "alice",
	})
	if err == nil {
		t.Fatal("expected hold on sold seat to fail")
	}
	invErr, ok := err.(*Error)
	if !ok || invErr.Code != ErrCodeSeatAlreadySold {
		t.Fatalf("expected ErrCodeSeatAlreadySold, got %v", err)
	}
}

func TestHoldSeat_ExpiresAfterTTL(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	_, err := env.seatSvc.HoldSeat(ctx, HoldSeatParams{
		OccurrenceID: "occ1",
		SeatID:       "A1",
		UserID:       "alice",
		TTL:          2 * time.Second,
	})
	if err != nil {
		t.Fatalf("hold: %v", err)
	}

	env.mr.FastForward(3 * time.Second)

	seatKey := omnitixredis.SeatHoldKey("occ1", "A1")
	exists, _ := env.rdb.Exists(ctx, seatKey).Result()
	if exists != 0 {
		t.Fatal("expected hold key to be gone after TTL")
	}
}

func TestReleaseHold_OnlyOwnerCanRelease(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	hold, err := env.seatSvc.HoldSeat(ctx, HoldSeatParams{
		OccurrenceID: "occ1",
		SeatID:       "A1",
		UserID:       "alice",
	})
	if err != nil {
		t.Fatalf("hold: %v", err)
	}

	// Bob tries to release Alice's hold.
	_ = env.seatSvc.ReleaseHold(ctx, ReleaseHoldParams{
		Token:  hold.Token,
		UserID: "bob",
	})

	seatKey := omnitixredis.SeatHoldKey("occ1", "A1")
	exists, _ := env.rdb.Exists(ctx, seatKey).Result()
	if exists != 1 {
		t.Fatal("bob should NOT have released alice's hold")
	}

	// Alice releases her own hold.
	if err := env.seatSvc.ReleaseHold(ctx, ReleaseHoldParams{
		Token:  hold.Token,
		UserID: "alice",
	}); err != nil {
		t.Fatalf("alice release: %v", err)
	}
	exists, _ = env.rdb.Exists(ctx, seatKey).Result()
	if exists != 0 {
		t.Fatal("alice's own release should have deleted the hold")
	}

	members, _ := env.rdb.SMembers(ctx, omnitixredis.HeldSeatsSetKey("occ1")).Result()
	if len(members) != 0 {
		t.Fatalf("held set should be empty after release, got %v", members)
	}
}

func TestConfirmHold_MovesSeatToSold(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	hold, err := env.seatSvc.HoldSeat(ctx, HoldSeatParams{
		OccurrenceID: "occ1",
		SeatID:       "A1",
		UserID:       "alice",
	})
	if err != nil {
		t.Fatalf("hold: %v", err)
	}

	if err := env.seatSvc.ConfirmHold(ctx, ConfirmHoldParams{
		Token:   hold.Token,
		UserID:  "alice",
		OrderID: "order1",
	}); err != nil {
		t.Fatalf("confirm: %v", err)
	}

	sold, _ := env.rdb.SIsMember(ctx, omnitixredis.SoldSeatsSetKey("occ1"), "A1").Result()
	if !sold {
		t.Fatal("expected A1 in sold set")
	}
	held, _ := env.rdb.SIsMember(ctx, omnitixredis.HeldSeatsSetKey("occ1"), "A1").Result()
	if held {
		t.Fatal("expected A1 removed from held set")
	}
	exists, _ := env.rdb.Exists(ctx, omnitixredis.SeatHoldKey("occ1", "A1")).Result()
	if exists != 0 {
		t.Fatal("expected seat hold key gone after confirm")
	}
}

// ─── GA hold tests ───────────────────────────────────────────

func TestHoldGaZone_AtomicCapacityCheck(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	occID := "occ1"
	zoneID := "PIT"

	if err := env.gaSvc.EnsureCapacityKey(ctx, occID, zoneID, 10); err != nil {
		t.Fatalf("ensure capacity: %v", err)
	}

	_, remaining, err := env.gaSvc.HoldGaZone(ctx, HoldGaParams{
		OccurrenceID: occID,
		GaZoneID:     zoneID,
		UserID:       "alice",
		Quantity:     4,
	})
	if err != nil {
		t.Fatalf("alice hold: %v", err)
	}
	if remaining != 6 {
		t.Fatalf("expected remaining 6, got %d", remaining)
	}

	_, remaining, err = env.gaSvc.HoldGaZone(ctx, HoldGaParams{
		OccurrenceID: occID,
		GaZoneID:     zoneID,
		UserID:       "bob",
		Quantity:     5,
	})
	if err != nil {
		t.Fatalf("bob hold: %v", err)
	}
	if remaining != 1 {
		t.Fatalf("expected remaining 1, got %d", remaining)
	}

	_, _, err = env.gaSvc.HoldGaZone(ctx, HoldGaParams{
		OccurrenceID: occID,
		GaZoneID:     zoneID,
		UserID:       "carol",
		Quantity:     3,
	})
	if err == nil {
		t.Fatal("expected carol's hold to fail")
	}
	invErr, ok := err.(*Error)
	if !ok || invErr.Code != ErrCodeGaInsufficient {
		t.Fatalf("expected ErrCodeGaInsufficient, got %v", err)
	}
}

func TestHoldGaZone_MissingCapacity(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	_, _, err := env.gaSvc.HoldGaZone(ctx, HoldGaParams{
		OccurrenceID: "occ1",
		GaZoneID:     "PIT",
		UserID:       "alice",
		Quantity:     1,
	})
	if err == nil {
		t.Fatal("expected error for missing capacity key")
	}
	invErr, ok := err.(*Error)
	if !ok || invErr.Code != ErrCodeGaCapacityMissing {
		t.Fatalf("expected ErrCodeGaCapacityMissing, got %v", err)
	}
}

// TestHoldGaZone_ConcurrentCallsNeverOversell is the test that proves the
// Lua-based atomic capacity check (HoldGaZone script) actually prevents
// overselling under contention. Without this, the atomicity fix is unverified.
//
// Setup: capacity = 10, 20 goroutines each try to hold 1 unit simultaneously.
// Expected: exactly 10 succeed, 10 fail with ErrCodeGaInsufficient, and
// the final available count is 0 — never negative.
func TestHoldGaZone_ConcurrentCallsNeverOversell(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	const (
		occID      = "occ1"
		zoneID     = "PIT"
		capacity   = 10
		contenders = 20
	)

	if err := env.gaSvc.EnsureCapacityKey(ctx, occID, zoneID, capacity); err != nil {
		t.Fatalf("ensure capacity: %v", err)
	}

	var (
		wg        sync.WaitGroup
		successes atomic.Int32
		rejected  atomic.Int32
	)

	// Release all goroutines at the same instant to maximize contention.
	start := make(chan struct{})

	for i := 0; i < contenders; i++ {
		wg.Add(1)
		go func(n int) {
			defer wg.Done()
			<-start

			_, _, err := env.gaSvc.HoldGaZone(ctx, HoldGaParams{
				OccurrenceID: occID,
				GaZoneID:     zoneID,
				UserID:       fmt.Sprintf("user-%d", n),
				Quantity:     1,
			})
			if err == nil {
				successes.Add(1)
				return
			}
			if invErr, ok := err.(*Error); ok && invErr.Code == ErrCodeGaInsufficient {
				rejected.Add(1)
				return
			}
			t.Errorf("unexpected error from contender %d: %v", n, err)
		}(i)
	}

	close(start)
	wg.Wait()

	if got := successes.Load(); got != capacity {
		t.Fatalf("expected exactly %d successful holds, got %d", capacity, got)
	}
	if got := rejected.Load(); got != contenders-capacity {
		t.Fatalf("expected exactly %d rejected holds, got %d", contenders-capacity, got)
	}

	// Final availability must be exactly zero — never negative.
	gotCapacity, held, sold, available, err := env.gaSvc.GetGaAvailability(ctx, occID, zoneID)
	if err != nil {
		t.Fatalf("get availability: %v", err)
	}
	if gotCapacity != capacity {
		t.Fatalf("capacity drifted: expected %d, got %d", capacity, gotCapacity)
	}
	if held != capacity {
		t.Fatalf("expected held == %d, got %d", capacity, held)
	}
	if sold != 0 {
		t.Fatalf("expected sold == 0, got %d", sold)
	}
	if available != 0 {
		t.Fatalf("expected available == 0 (no oversell, no underflow), got %d", available)
	}
}

func TestConfirmGaZone_MovesHeldToSold(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	occID, zoneID := "occ1", "PIT"
	if err := env.gaSvc.EnsureCapacityKey(ctx, occID, zoneID, 10); err != nil {
		t.Fatalf("ensure capacity: %v", err)
	}

	hold, _, err := env.gaSvc.HoldGaZone(ctx, HoldGaParams{
		OccurrenceID: occID,
		GaZoneID:     zoneID,
		UserID:       "alice",
		Quantity:     4,
	})
	if err != nil {
		t.Fatalf("hold: %v", err)
	}

	if err := env.gaSvc.ConfirmGaZone(ctx, ConfirmGaParams{
		Token:   hold.Token,
		UserID:  "alice",
		OrderID: "order1",
	}); err != nil {
		t.Fatalf("confirm: %v", err)
	}

	capacity, held, sold, available, err := env.gaSvc.GetGaAvailability(ctx, occID, zoneID)
	if err != nil {
		t.Fatalf("get availability: %v", err)
	}
	if capacity != 10 || held != 0 || sold != 4 || available != 6 {
		t.Fatalf("expected (10,0,4,6), got (%d,%d,%d,%d)",
			capacity, held, sold, available)
	}
}

// ─── Best-available tests ────────────────────────────────────

func TestBestAvailable_PicksContiguousRun(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	occID := "occ1"

	layout := &LayoutCache{
		OccurrenceID: occID,
		Sections: map[string][]SeatInfo{
			"ORCH": {
				{SeatID: "A1", SeatLabel: "A1", Section: "ORCH", Row: "A", SeatNumber: 1},
				{SeatID: "A2", SeatLabel: "A2", Section: "ORCH", Row: "A", SeatNumber: 2},
				{SeatID: "A3", SeatLabel: "A3", Section: "ORCH", Row: "A", SeatNumber: 3},
				{SeatID: "A4", SeatLabel: "A4", Section: "ORCH", Row: "A", SeatNumber: 4},
				{SeatID: "A5", SeatLabel: "A5", Section: "ORCH", Row: "A", SeatNumber: 5},
			},
		},
	}
	if err := env.baSvc.CacheLayout(ctx, layout); err != nil {
		t.Fatalf("cache layout: %v", err)
	}

	result, err := env.baSvc.FindBestAvailable(ctx, BestAvailableParams{
		OccurrenceID: occID,
		SectionCode:  "ORCH",
		Quantity:     2,
		UserID:       "alice",
	})
	if err != nil {
		t.Fatalf("best available: %v", err)
	}
	if len(result.Holds) != 2 {
		t.Fatalf("expected 2 holds, got %d", len(result.Holds))
	}
}

func TestBestAvailable_RespectsOrphanRule(t *testing.T) {
	env := newTestEnv(t)
	ctx := context.Background()

	occID := "occ1"

	layout := &LayoutCache{
		OccurrenceID: occID,
		Sections: map[string][]SeatInfo{
			"ORCH": {
				{SeatID: "A1", SeatLabel: "A1", Section: "ORCH", Row: "A", SeatNumber: 1},
				{SeatID: "A2", SeatLabel: "A2", Section: "ORCH", Row: "A", SeatNumber: 2},
				{SeatID: "A3", SeatLabel: "A3", Section: "ORCH", Row: "A", SeatNumber: 3},
				{SeatID: "A4", SeatLabel: "A4", Section: "ORCH", Row: "A", SeatNumber: 4},
				{SeatID: "A5", SeatLabel: "A5", Section: "ORCH", Row: "A", SeatNumber: 5},
			},
		},
	}
	if err := env.baSvc.CacheLayout(ctx, layout); err != nil {
		t.Fatalf("cache layout: %v", err)
	}

	for _, sid := range []string{"A1", "A2", "A4", "A5"} {
		_, err := env.seatSvc.HoldSeat(ctx, HoldSeatParams{
			OccurrenceID: occID,
			SeatID:       sid,
			Section:      "ORCH",
			Row:          "A",
			UserID:       "alice",
		})
		if err != nil {
			t.Fatalf("pre-hold %s: %v", sid, err)
		}
	}

	// Bob asks for 2 → no contiguous pair exists.
	_, err := env.baSvc.FindBestAvailable(ctx, BestAvailableParams{
		OccurrenceID: occID,
		SectionCode:  "ORCH",
		Quantity:     2,
		UserID:       "bob",
	})
	if err == nil {
		t.Fatal("expected best available to fail when no contiguous pair exists")
	}
}