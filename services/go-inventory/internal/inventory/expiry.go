package inventory

import (
	"context"
	"fmt"

	"github.com/omnitix/go-inventory/internal/logger"
	"github.com/omnitix/go-inventory/internal/redis"
	"go.uber.org/zap"
)

// ExpiryService handles Redis key-expiry events by emitting the
// corresponding `seat.expired` / `ga.expired` Kafka events.
//
// It implements the redis.ExpiryHandler interface.
type ExpiryService struct {
	rdb       *redis.Client
	publisher Publisher
	log       *logger.Logger
	seatSvc   *SeatService
	gaSvc     *GaService
}

// NewExpiryService constructs the expiry-event handler.
func NewExpiryService(
	rdb *redis.Client,
	publisher Publisher,
	seatSvc *SeatService,
	gaSvc *GaService,
	log *logger.Logger,
) *ExpiryService {
	return &ExpiryService{
		rdb:       rdb,
		publisher: publisher,
		seatSvc:   seatSvc,
		gaSvc:     gaSvc,
		log:       log.Named("expiry"),
	}
}

// OnSeatExpired is called by the Redis subscriber when a seat-hold key's
// TTL elapses. It recovers the token payload, then publishes `seat.expired`.
func (s *ExpiryService) OnSeatExpired(ctx context.Context, occurrenceID, seatID string) error {
	// Remove from the held set first — this is what makes the seat
	// "available" to the read side (GetSeatStates, getTakenSeats).
	heldSetKey := redis.HeldSeatsSetKey(occurrenceID)
	if err := s.rdb.SRem(ctx, heldSetKey, seatID).Err(); err != nil {
		s.log.Warn("could not remove seat from held set",
			zap.String("occurrence_id", occurrenceID),
			zap.String("seat_id", seatID),
			zap.Error(err),
		)
	}

	// Recover the token payload. The token key outlives the hold key by
	// TokenTTLBuffer (30s), so the lookup should succeed.
	tp, token, err := s.rdb.RecoverTokenPayloadByOccurrenceSeat(ctx, occurrenceID, seatID)
	if err != nil {
		s.log.Warn("could not recover token for expired seat; publishing bare event",
			zap.String("occurrence_id", occurrenceID),
			zap.String("seat_id", seatID),
			zap.Error(err),
		)
		return s.publishSeatExpired(ctx, SeatExpiredPayload{
			EventOccurrenceID: occurrenceID,
			SeatID:            seatID,
			UserID:            "",
			HoldToken:         "",
		})
	}

	// Clean up the token key now that we've recovered its contents.
	_ = s.rdb.Del(ctx, redis.TokenKey(token)).Err()

	return s.publishSeatExpired(ctx, SeatExpiredPayload{
		EventOccurrenceID: occurrenceID,
		SeatID:            seatID,
		UserID:            tp.UserID,
		HoldToken:         token,
	})
}

// OnGaExpired is called when a GA held-counter key's TTL elapses.
func (s *ExpiryService) OnGaExpired(ctx context.Context, occurrenceID, zoneID string) error {
	tp, token, err := s.rdb.RecoverGaTokenPayload(ctx, occurrenceID, zoneID)
	if err != nil {
		s.log.Debug("no token found for expired GA zone; publishing bare event",
			zap.String("occurrence_id", occurrenceID),
			zap.String("ga_zone_id", zoneID),
		)
		remaining := s.gaAvailable(ctx, occurrenceID, zoneID)
		return s.publishGaExpired(ctx, GaExpiredPayload{
			EventOccurrenceID: occurrenceID,
			GaZoneID:          zoneID,
			Quantity:          0,
			RemainingCapacity: remaining,
		})
	}

	_ = s.rdb.Del(ctx, redis.TokenKey(token)).Err()

	remaining := s.gaAvailable(ctx, occurrenceID, zoneID)

	return s.publishGaExpired(ctx, GaExpiredPayload{
		EventOccurrenceID: occurrenceID,
		GaZoneID:          zoneID,
		Quantity:          tp.Quantity,
		UserID:            tp.UserID,
		HoldToken:         token,
		RemainingCapacity: remaining,
	})
}

// ─── Internal ────────────────────────────────────────────────

func (s *ExpiryService) publishSeatExpired(ctx context.Context, payload SeatExpiredPayload) error {
	if s.publisher == nil {
		return nil
	}
	if err := s.publisher.Publish(
		ctx,
		TopicSeatExpired,
		payload.EventOccurrenceID,
		"",
		payload,
	); err != nil {
		return fmt.Errorf("publish %s: %w", TopicSeatExpired, err)
	}
	return nil
}

func (s *ExpiryService) publishGaExpired(ctx context.Context, payload GaExpiredPayload) error {
	if s.publisher == nil {
		return nil
	}
	if err := s.publisher.Publish(
		ctx,
		TopicGaExpired,
		payload.EventOccurrenceID,
		"",
		payload,
	); err != nil {
		return fmt.Errorf("publish %s: %w", TopicGaExpired, err)
	}
	return nil
}

// gaAvailable computes remaining capacity for a GA zone as a best-effort
// snapshot at expiry time.
func (s *ExpiryService) gaAvailable(ctx context.Context, occurrenceID, zoneID string) int {
	_, _, _, available, err := s.gaSvc.GetGaAvailability(ctx, occurrenceID, zoneID)
	if err != nil {
		s.log.Warn("could not compute GA availability at expiry",
			zap.String("occurrence_id", occurrenceID),
			zap.String("ga_zone_id", zoneID),
			zap.Error(err),
		)
		return 0
	}
	return available
}