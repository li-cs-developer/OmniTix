package inventory

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/omnitix/go-inventory/internal/logger"
	"github.com/omnitix/go-inventory/internal/redis"
	goredis "github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

// GaService implements atomic GA zone holds on top of Redis.
type GaService struct {
	rdb       *redis.Client
	scripts   *redis.Scripts
	publisher Publisher
	log       *logger.Logger

	defaultTTL time.Duration
}

// NewGaService constructs the GA service.
func NewGaService(
	rdb *redis.Client,
	scripts *redis.Scripts,
	publisher Publisher,
	log *logger.Logger,
	defaultTTL time.Duration,
) *GaService {
	return &GaService{
		rdb:        rdb,
		scripts:    scripts,
		publisher:  publisher,
		log:        log.Named("ga"),
		defaultTTL: defaultTTL,
	}
}

// EnsureCapacityKey sets the capacity for a GA zone if it doesn't already
// exist. Called when an event goes on sale. Idempotent.
func (s *GaService) EnsureCapacityKey(ctx context.Context, occurrenceID, zoneID string, capacity int) error {
	key := redis.GaCapacityKey(occurrenceID, zoneID)

	ok, err := s.rdb.SetNX(ctx, key, capacity, 0).Result()
	if err != nil {
		return fmt.Errorf("set capacity: %w", err)
	}
	if !ok {
		s.log.Debug("capacity key already exists, skipping",
			zap.String("occurrence_id", occurrenceID),
			zap.String("zone_id", zoneID),
		)
	}
	return nil
}

// HoldGaParams carries the input to a HoldGaZone call.
type HoldGaParams struct {
	OccurrenceID string
	GaZoneID     string
	GaZoneName   string
	UserID       string
	Quantity     int
	TTL          time.Duration // 0 = use service default
}

// HoldGaZone attempts to atomically reserve N units from a GA zone.
//
// All reads of capacity/sold/held happen inside a single Lua script, so
// there is no window where two callers can both pass the capacity check
// against a stale view.
func (s *GaService) HoldGaZone(ctx context.Context, p HoldGaParams) (*Hold, int, error) {
	if p.OccurrenceID == "" || p.GaZoneID == "" || p.UserID == "" {
		return nil, 0, NewError(ErrCodeInvalidRequest, "missing required fields")
	}
	if p.Quantity <= 0 {
		return nil, 0, NewError(ErrCodeInvalidRequest, "quantity must be positive")
	}

	ttl := p.TTL
	if ttl <= 0 {
		ttl = s.defaultTTL
	}

	heldKey := redis.GaHeldKey(p.OccurrenceID, p.GaZoneID)
	soldKey := redis.GaSoldKey(p.OccurrenceID, p.GaZoneID)
	capacityKey := redis.GaCapacityKey(p.OccurrenceID, p.GaZoneID)

	// ─── Atomic hold via Lua ────────────────────────────
	newHeld, err := s.scripts.HoldGaZone.Run(
		ctx, s.rdb.Client,
		[]string{heldKey, soldKey, capacityKey},
		p.Quantity, int(ttl.Seconds()),
	).Int()
	if err != nil {
		return nil, 0, fmt.Errorf("hold ga script: %w", err)
	}

	switch newHeld {
	case -1:
		return nil, 0, NewError(ErrCodeGaInsufficient,
			fmt.Sprintf("not enough capacity for %d units", p.Quantity))
	case -2:
		return nil, 0, NewError(ErrCodeGaCapacityMissing,
			"GA zone capacity not initialized; call EnsureCapacityKey first")
	}

	// ─── Compute remaining for the response ─────────────
	// Extra GETs here are for UI feedback only, not correctness.
	capacity, _ := s.rdb.Get(ctx, capacityKey).Int()
	sold, _ := s.rdb.Get(ctx, soldKey).Int()
	remaining := capacity - sold - newHeld
	if remaining < 0 {
		remaining = 0
	}

	// ─── Build the Hold struct ──────────────────────────
	token := newToken()
	now := time.Now().UTC()
	hold := &Hold{
		Token:        token,
		Kind:         "ga",
		OccurrenceID: p.OccurrenceID,
		GaZoneID:     p.GaZoneID,
		GaZoneName:   p.GaZoneName,
		Quantity:     p.Quantity,
		UserID:       p.UserID,
		ExpiresAt:    now.Add(ttl),
		CreatedAt:    now,
	}

	// ─── Store token → zone mapping ─────────────────────
	tokenPayload, _ := json.Marshal(map[string]any{
		"kind":          "ga",
		"occurrence_id": p.OccurrenceID,
		"ga_zone_id":    p.GaZoneID,
		"user_id":       p.UserID,
		"quantity":      p.Quantity,
	})
	tokenKey := redis.TokenKey(token)
	if err := s.rdb.Set(ctx, tokenKey, tokenPayload, ttl+redis.TokenTTLBuffer).Err(); err != nil {
		_ = s.rdb.DecrBy(ctx, heldKey, int64(p.Quantity)).Err()
		return nil, 0, fmt.Errorf("set token: %w", err)
	}

	s.log.Info("ga zone held",
		zap.String("occurrence_id", p.OccurrenceID),
		zap.String("ga_zone_id", p.GaZoneID),
		zap.String("user_id", p.UserID),
		zap.Int("quantity", p.Quantity),
		zap.Int("new_held", newHeld),
		zap.Int("remaining", remaining),
		zap.String("token", token),
		zap.Duration("ttl", ttl),
	)

	// ─── Emit ga.held event ─────────────────────────────
	if s.publisher != nil {
		_ = s.publisher.Publish(
			ctx,
			TopicGaHeld,
			p.OccurrenceID,
			"",
			GaHeldPayload{
				EventOccurrenceID: p.OccurrenceID,
				GaZoneID:          p.GaZoneID,
				ZoneName:          p.GaZoneName,
				Quantity:          p.Quantity,
				UserID:            p.UserID,
				HoldToken:         token,
				ExpiresAt:         hold.ExpiresAt,
				RemainingCapacity: remaining,
			},
		)
	}

	return hold, remaining, nil
}

// ReleaseGaParams carries the input to a ReleaseGaZone call.
type ReleaseGaParams struct {
	Token  string
	UserID string
}

// ReleaseGaZone releases N units back to the pool.
// Idempotent: releasing a non-existent or expired hold returns nil.
func (s *GaService) ReleaseGaZone(ctx context.Context, p ReleaseGaParams) error {
	if p.Token == "" || p.UserID == "" {
		return NewError(ErrCodeInvalidRequest, "missing token or user_id")
	}

	tokenKey := redis.TokenKey(p.Token)
	tokenPayload, err := s.rdb.Get(ctx, tokenKey).Result()
	if errors.Is(err, goredis.Nil) {
		s.log.Debug("release: token not found, no-op", zap.String("token", p.Token))
		return nil
	}
	if err != nil {
		return fmt.Errorf("get token: %w", err)
	}

	var meta struct {
		Kind         string `json:"kind"`
		OccurrenceID string `json:"occurrence_id"`
		GaZoneID     string `json:"ga_zone_id"`
		Quantity     int    `json:"quantity"`
	}
	if err := json.Unmarshal([]byte(tokenPayload), &meta); err != nil {
		return fmt.Errorf("unmarshal token: %w", err)
	}
	if meta.Kind != "ga" {
		return NewError(ErrCodeInvalidRequest, "token is not for a GA hold")
	}

	heldKey := redis.GaHeldKey(meta.OccurrenceID, meta.GaZoneID)
	newHeld, err := s.scripts.ReleaseGaIfOwner.Run(
		ctx, s.rdb.Client,
		[]string{heldKey, tokenKey},
		p.UserID, meta.Quantity,
	).Int()
	if err != nil {
		return fmt.Errorf("release ga script: %w", err)
	}

	_ = s.rdb.Del(ctx, tokenKey).Err()

	s.log.Info("ga zone released",
		zap.String("occurrence_id", meta.OccurrenceID),
		zap.String("ga_zone_id", meta.GaZoneID),
		zap.String("user_id", p.UserID),
		zap.Int("quantity", meta.Quantity),
		zap.Int("new_held", newHeld),
	)

	return nil
}

// ConfirmGaParams carries the input to a ConfirmGaZone call.
type ConfirmGaParams struct {
	Token   string
	UserID  string
	OrderID string
}

// ConfirmGaZone converts held units to sold. Called after payment succeeds.
// Idempotent: confirming an already-confirmed hold returns nil.
func (s *GaService) ConfirmGaZone(ctx context.Context, p ConfirmGaParams) error {
	if p.Token == "" || p.UserID == "" {
		return NewError(ErrCodeInvalidRequest, "missing token or user_id")
	}

	tokenKey := redis.TokenKey(p.Token)
	tokenPayload, err := s.rdb.Get(ctx, tokenKey).Result()
	if errors.Is(err, goredis.Nil) {
		s.log.Debug("confirm: token not found, assuming already confirmed",
			zap.String("token", p.Token),
		)
		return nil
	}
	if err != nil {
		return fmt.Errorf("get token: %w", err)
	}

	var meta struct {
		Kind         string `json:"kind"`
		OccurrenceID string `json:"occurrence_id"`
		GaZoneID     string `json:"ga_zone_id"`
		Quantity     int    `json:"quantity"`
	}
	if err := json.Unmarshal([]byte(tokenPayload), &meta); err != nil {
		return fmt.Errorf("unmarshal token: %w", err)
	}
	if meta.Kind != "ga" {
		return NewError(ErrCodeInvalidRequest, "token is not for a GA hold")
	}

	heldKey := redis.GaHeldKey(meta.OccurrenceID, meta.GaZoneID)
	soldKey := redis.GaSoldKey(meta.OccurrenceID, meta.GaZoneID)

	result, err := s.scripts.ConfirmGaIfOwner.Run(
		ctx, s.rdb.Client,
		[]string{heldKey, soldKey, tokenKey},
		p.UserID, meta.Quantity,
	).Int()
	if err != nil {
		return fmt.Errorf("confirm ga script: %w", err)
	}

	_ = s.rdb.Del(ctx, tokenKey).Err()

	if result != 1 {
		return NewError(ErrCodeHoldNotOwner, "hold is not owned by caller")
	}

	s.log.Info("ga zone confirmed (sold)",
		zap.String("occurrence_id", meta.OccurrenceID),
		zap.String("ga_zone_id", meta.GaZoneID),
		zap.String("user_id", p.UserID),
		zap.Int("quantity", meta.Quantity),
		zap.String("order_id", p.OrderID),
	)

	// ─── Emit ga.sold event ─────────────────────────────
	if s.publisher != nil {
		_ = s.publisher.Publish(
			ctx,
			TopicGaSold,
			meta.OccurrenceID,
			"",
			GaSoldPayload{
				EventOccurrenceID: meta.OccurrenceID,
				GaZoneID:          meta.GaZoneID,
				Quantity:          meta.Quantity,
				OrderID:           p.OrderID,
				UserID:            p.UserID,
				SoldAt:            time.Now().UTC(),
				TotalPriceCents:   0,
				Currency:          "USD",
			},
		)
	}

	return nil
}

// GetGaAvailability returns the current availability for a GA zone.
func (s *GaService) GetGaAvailability(ctx context.Context, occurrenceID, zoneID string) (capacity, held, sold, available int, err error) {
	capacityKey := redis.GaCapacityKey(occurrenceID, zoneID)
	heldKey := redis.GaHeldKey(occurrenceID, zoneID)
	soldKey := redis.GaSoldKey(occurrenceID, zoneID)

	pipe := s.rdb.Pipeline()
	capCmd := pipe.Get(ctx, capacityKey)
	heldCmd := pipe.Get(ctx, heldKey)
	soldCmd := pipe.Get(ctx, soldKey)

	_, _ = pipe.Exec(ctx)

	capacity = parseIntIgnoreNil(capCmd)
	held = parseIntIgnoreNil(heldCmd)
	sold = parseIntIgnoreNil(soldCmd)
	available = capacity - held - sold
	if available < 0 {
		available = 0
	}

	return capacity, held, sold, available, nil
}

func parseIntIgnoreNil(cmd *goredis.StringCmd) int {
	v, err := cmd.Int()
	if err != nil {
		return 0
	}
	return v
}

// RawDel deletes a key directly. Used only for smoke test cleanup.
func (s *GaService) RawDel(ctx context.Context, key string) error {
	return s.rdb.Del(ctx, key).Err()
}