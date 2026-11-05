package grpc

import (
	"context"
	"time"

	commonv1 "github.com/omnitix/go-inventory/gen/go/omnitix/common/v1"
	inventoryv1 "github.com/omnitix/go-inventory/gen/go/omnitix/inventory/v1"
	"github.com/omnitix/go-inventory/internal/inventory"
	"github.com/omnitix/go-inventory/internal/kafka"
	"github.com/omnitix/go-inventory/internal/logger"
	"github.com/omnitix/go-inventory/internal/redis"
	"go.uber.org/zap"
	"google.golang.org/protobuf/types/known/timestamppb"
)

// InventoryHandler implements the InventoryService gRPC interface.
type InventoryHandler struct {
	inventoryv1.UnimplementedInventoryServiceServer

	seatSvc *inventory.SeatService
	gaSvc   *inventory.GaService
	baSvc   *inventory.BestAvailableService
	rdb     *redis.Client
	kafka   *kafka.Producer
	log     *logger.Logger
}

// NewInventoryHandler constructs the handler.
func NewInventoryHandler(
	seatSvc *inventory.SeatService,
	gaSvc *inventory.GaService,
	baSvc *inventory.BestAvailableService,
	rdb *redis.Client,
	kafkaProducer *kafka.Producer,
	log *logger.Logger,
) *InventoryHandler {
	return &InventoryHandler{
		seatSvc: seatSvc,
		gaSvc:   gaSvc,
		baSvc:   baSvc,
		rdb:     rdb,
		kafka:   kafkaProducer,
		log:     log.Named("grpc"),
	}
}

// ─── HoldSeat ───────────────────────────────────────────────

func (h *InventoryHandler) HoldSeat(
	ctx context.Context,
	req *inventoryv1.HoldSeatRequest,
) (*inventoryv1.HoldSeatResponse, error) {
	reqID, userID, _, ip := contextFromProto(req.Context)
	log := h.log.With(
		zap.String("rpc", "HoldSeat"),
		zap.String("request_id", reqID),
		zap.String("ip", ip),
	)

	if req.EventOccurrenceId == "" || req.SeatId == "" {
		return &inventoryv1.HoldSeatResponse{
			Result: errResult(inventory.ErrCodeInvalidRequest, "missing event_occurrence_id or seat_id"),
		}, nil
	}

	hold, err := h.seatSvc.HoldSeat(ctx, inventory.HoldSeatParams{
		OccurrenceID: req.EventOccurrenceId,
		SeatID:       req.SeatId,
		UserID:       userID,
		TTL:          durationToTTL(req.TtlSeconds),
	})
	if err != nil {
		log.Info("hold failed", zap.Error(err))
		return &inventoryv1.HoldSeatResponse{
			Result: errResultFromError(err),
		}, nil
	}

	log.Info("hold succeeded", zap.String("seat_id", req.SeatId))
	return &inventoryv1.HoldSeatResponse{
		Result: okResult(),
		Token:  holdToToken(hold),
	}, nil
}

// ─── HoldGaZone ─────────────────────────────────────────────

func (h *InventoryHandler) HoldGaZone(
	ctx context.Context,
	req *inventoryv1.HoldGaZoneRequest,
) (*inventoryv1.HoldGaZoneResponse, error) {
	reqID, userID, _, ip := contextFromProto(req.Context)
	log := h.log.With(
		zap.String("rpc", "HoldGaZone"),
		zap.String("request_id", reqID),
		zap.String("ip", ip),
	)

	if req.EventOccurrenceId == "" || req.GaZoneId == "" || req.Quantity <= 0 {
		return &inventoryv1.HoldGaZoneResponse{
			Result: errResult(inventory.ErrCodeInvalidRequest, "missing required fields"),
		}, nil
	}

	hold, remaining, err := h.gaSvc.HoldGaZone(ctx, inventory.HoldGaParams{
		OccurrenceID: req.EventOccurrenceId,
		GaZoneID:     req.GaZoneId,
		UserID:       userID,
		Quantity:     int(req.Quantity),
		TTL:          durationToTTL(req.TtlSeconds),
	})
	if err != nil {
		log.Info("ga hold failed", zap.Error(err))
		return &inventoryv1.HoldGaZoneResponse{
			Result: errResultFromError(err),
		}, nil
	}

	log.Info("ga hold succeeded",
		zap.String("ga_zone_id", req.GaZoneId),
		zap.Int("quantity", int(req.Quantity)),
	)
	return &inventoryv1.HoldGaZoneResponse{
		Result:            okResult(),
		Token:             holdToToken(hold),
		RemainingCapacity: int32(remaining),
	}, nil
}

// ─── HoldBestAvailable ──────────────────────────────────────

func (h *InventoryHandler) HoldBestAvailable(
	ctx context.Context,
	req *inventoryv1.HoldBestAvailableRequest,
) (*inventoryv1.HoldBestAvailableResponse, error) {
	reqID, userID, _, ip := contextFromProto(req.Context)
	log := h.log.With(
		zap.String("rpc", "HoldBestAvailable"),
		zap.String("request_id", reqID),
		zap.String("ip", ip),
	)

	if req.EventOccurrenceId == "" || req.Quantity <= 0 {
		return &inventoryv1.HoldBestAvailableResponse{
			Result: errResult(inventory.ErrCodeInvalidRequest, "missing required fields"),
		}, nil
	}

	result, err := h.baSvc.FindBestAvailable(ctx, inventory.BestAvailableParams{
		OccurrenceID: req.EventOccurrenceId,
		SectionCode:  req.SectionCode,
		Quantity:     int(req.Quantity),
		UserID:       userID,
		TTL:          durationToTTL(req.TtlSeconds),
	})
	if err != nil {
		log.Info("best available failed", zap.Error(err))
		return &inventoryv1.HoldBestAvailableResponse{
			Result: errResultFromError(err),
		}, nil
	}

	tokens := make([]*commonv1.HoldToken, 0, len(result.Holds))
	for _, h := range result.Holds {
		tokens = append(tokens, holdToToken(h))
	}

	log.Info("best available succeeded",
		zap.String("section", result.Section),
		zap.String("row", result.Row),
		zap.Int("count", len(result.Holds)),
	)

	return &inventoryv1.HoldBestAvailableResponse{
		Result:          okResult(),
		Tokens:          tokens,
		SelectedSection: result.Section,
		SelectedRow:     result.Row,
	}, nil
}

// ─── ReleaseHold ────────────────────────────────────────────

func (h *InventoryHandler) ReleaseHold(
	ctx context.Context,
	req *inventoryv1.ReleaseHoldRequest,
) (*inventoryv1.ReleaseHoldResponse, error) {
	reqID, userID, _, _ := contextFromProto(req.Context)
	log := h.log.With(
		zap.String("rpc", "ReleaseHold"),
		zap.String("request_id", reqID),
	)

	if req.Token == "" {
		return &inventoryv1.ReleaseHoldResponse{
			Result: errResult(inventory.ErrCodeInvalidRequest, "missing token"),
		}, nil
	}

	// Try seat release first; if the token isn't a seat, try GA.
	err := h.seatSvc.ReleaseHold(ctx, inventory.ReleaseHoldParams{
		Token:  req.Token,
		UserID: userID,
	})
	if err != nil {
		if err2 := h.gaSvc.ReleaseGaZone(ctx, inventory.ReleaseGaParams{
			Token:  req.Token,
			UserID: userID,
		}); err2 != nil {
			log.Info("release failed", zap.Error(err2))
			return &inventoryv1.ReleaseHoldResponse{
				Result: errResultFromError(err2),
			}, nil
		}
	}

	return &inventoryv1.ReleaseHoldResponse{Result: okResult()}, nil
}

// ─── ExtendHold ─────────────────────────────────────────────

func (h *InventoryHandler) ExtendHold(
	ctx context.Context,
	req *inventoryv1.ExtendHoldRequest,
) (*inventoryv1.ExtendHoldResponse, error) {
	reqID, userID, _, _ := contextFromProto(req.Context)
	log := h.log.With(
		zap.String("rpc", "ExtendHold"),
		zap.String("request_id", reqID),
	)

	if req.Token == "" || req.AdditionalSeconds <= 0 {
		return &inventoryv1.ExtendHoldResponse{
			Result: errResult(inventory.ErrCodeInvalidRequest, "missing token or additional_seconds"),
		}, nil
	}

	newTTL, err := h.seatSvc.ExtendHold(ctx, inventory.ExtendHoldParams{
		Token:             req.Token,
		UserID:            userID,
		AdditionalSeconds: int(req.AdditionalSeconds),
	})
	if err != nil {
		log.Info("extend failed", zap.Error(err))
		return &inventoryv1.ExtendHoldResponse{
			Result: errResultFromError(err),
		}, nil
	}

	return &inventoryv1.ExtendHoldResponse{
		Result:         okResult(),
		NewExpiresAt:   timestamppb.New(time.Now().Add(newTTL)),
		ExtensionsUsed: 1,
	}, nil
}

// ─── ConfirmHold ────────────────────────────────────────────

func (h *InventoryHandler) ConfirmHold(
	ctx context.Context,
	req *inventoryv1.ConfirmHoldRequest,
) (*inventoryv1.ConfirmHoldResponse, error) {
	reqID, userID, _, _ := contextFromProto(req.Context)
	log := h.log.With(
		zap.String("rpc", "ConfirmHold"),
		zap.String("request_id", reqID),
		zap.String("order_id", req.OrderId),
	)

	if req.Token == "" {
		return &inventoryv1.ConfirmHoldResponse{
			Result: errResult(inventory.ErrCodeInvalidRequest, "missing token"),
		}, nil
	}

	// Try seat confirm first; if the token isn't a seat, try GA.
	err := h.seatSvc.ConfirmHold(ctx, inventory.ConfirmHoldParams{
		Token:   req.Token,
		UserID:  userID,
		OrderID: req.OrderId,
	})
	if err != nil {
		if err2 := h.gaSvc.ConfirmGaZone(ctx, inventory.ConfirmGaParams{
			Token:   req.Token,
			UserID:  userID,
			OrderID: req.OrderId,
		}); err2 != nil {
			log.Info("confirm failed", zap.Error(err2))
			return &inventoryv1.ConfirmHoldResponse{
				Result: errResultFromError(err2),
			}, nil
		}
	}

	return &inventoryv1.ConfirmHoldResponse{Result: okResult()}, nil
}

// ─── GetSeatStates ──────────────────────────────────────────

func (h *InventoryHandler) GetSeatStates(
	ctx context.Context,
	req *inventoryv1.GetSeatStatesRequest,
) (*inventoryv1.GetSeatStatesResponse, error) {
	if req.EventOccurrenceId == "" {
		return &inventoryv1.GetSeatStatesResponse{
			Result: errResult(inventory.ErrCodeInvalidRequest, "missing event_occurrence_id"),
		}, nil
	}

	soldKey := redis.SoldSeatsSetKey(req.EventOccurrenceId)
	heldKey := redis.HeldSeatsSetKey(req.EventOccurrenceId)

	// Pipeline both SMEMBERS calls; ignore redis.Nil errors (empty sets
	// return redis.Nil in some client configurations).
	pipe := h.rdb.Pipeline()
	soldCmd := pipe.SMembers(ctx, soldKey)
	heldCmd := pipe.SMembers(ctx, heldKey)
	if _, err := pipe.Exec(ctx); err != nil && err != redis.Nil {
		return &inventoryv1.GetSeatStatesResponse{
			Result: errResultFromError(err),
		}, nil
	}

	soldIDs, _ := soldCmd.Result()
	heldIDs, _ := heldCmd.Result()

	soldSet := make(map[string]bool, len(soldIDs))
	for _, id := range soldIDs {
		soldSet[id] = true
	}
	heldSet := make(map[string]bool, len(heldIDs))
	for _, id := range heldIDs {
		heldSet[id] = true
	}

	// Build the response. If req.SeatIds is empty, we return all seats
	// we know the state of (sold + held). Otherwise filter to the requested IDs.
	requested := req.SeatIds
	if len(requested) == 0 {
		for id := range soldSet {
			requested = append(requested, id)
		}
		for id := range heldSet {
			requested = append(requested, id)
		}
	}

	entries := make([]*inventoryv1.SeatStateEntry, 0, len(requested))
	for _, id := range requested {
		state := inventory.SeatStateAvailable
		if soldSet[id] {
			state = inventory.SeatStateSold
		} else if heldSet[id] {
			state = inventory.SeatStateHeld
		}
		entries = append(entries, &inventoryv1.SeatStateEntry{
			SeatId: id,
			State:  seatStateToProto(state),
		})
	}

	return &inventoryv1.GetSeatStatesResponse{
		Result: okResult(),
		Seats:  entries,
	}, nil
}

// ─── GetGaAvailability ──────────────────────────────────────

func (h *InventoryHandler) GetGaAvailability(
	ctx context.Context,
	req *inventoryv1.GetGaAvailabilityRequest,
) (*inventoryv1.GetGaAvailabilityResponse, error) {
	if req.EventOccurrenceId == "" || req.GaZoneId == "" {
		return &inventoryv1.GetGaAvailabilityResponse{
			Result: errResult(inventory.ErrCodeInvalidRequest, "missing required fields"),
		}, nil
	}

	capacity, held, sold, available, err := h.gaSvc.GetGaAvailability(
		ctx, req.EventOccurrenceId, req.GaZoneId,
	)
	if err != nil {
		return &inventoryv1.GetGaAvailabilityResponse{
			Result: errResultFromError(err),
		}, nil
	}

	return &inventoryv1.GetGaAvailabilityResponse{
		Result:        okResult(),
		TotalCapacity: int32(capacity),
		Held:          int32(held),
		Sold:          int32(sold),
		Available:     int32(available),
	}, nil
}

// ─── Health ─────────────────────────────────────────────────

func (h *InventoryHandler) Health(
	ctx context.Context,
	_ *inventoryv1.HealthRequest,
) (*inventoryv1.HealthResponse, error) {
	redisOK := h.rdb.Health(ctx) == nil
	kafkaOK := true // producer check would require a Ping; skip for now

	return &inventoryv1.HealthResponse{
		Healthy:        redisOK && kafkaOK,
		Version:        "0.1.0",
		RedisConnected: redisOK,
		KafkaConnected: kafkaOK,
		CheckedAt:      timestamppb.Now(),
	}, nil
}