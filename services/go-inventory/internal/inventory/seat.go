package inventory

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/omnitix/go-inventory/internal/logger"
	"github.com/omnitix/go-inventory/internal/redis"
	goredis "github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

// SeatService implements atomic seat holds on top of Redis.
type SeatService struct {
	rdb       *redis.Client
	scripts   *redis.Scripts
	publisher Publisher
	log       *logger.Logger

	defaultTTL time.Duration
	maxExtend  time.Duration
}

// NewSeatService constructs the seat service.
func NewSeatService(
	rdb *redis.Client,
	scripts *redis.Scripts,
	publisher Publisher,
	log *logger.Logger,
	defaultTTL time.Duration,
	maxExtend time.Duration,
) *SeatService {
	return &SeatService{
		rdb:        rdb,
		scripts:    scripts,
		publisher:  publisher,
		log:        log.Named("seat"),
		defaultTTL: defaultTTL,
		maxExtend:  maxExtend,
	}
}

// HoldSeatParams carries the input to a HoldSeat call.
type HoldSeatParams struct {
	OccurrenceID string
	SeatID       string
	SeatLabel    string
	Section      string
	Row          string
	SeatNumber   string
	UserID       string
	TTL          time.Duration // 0 = use service default
}

// HoldSeat attempts to atomically hold a specific seat.
//
// Returns:
//   - (*Hold, nil) on success
//   - (nil, *Error{Code: SEAT_ALREADY_HELD}) if another user holds it
//   - (nil, *Error{Code: SEAT_ALREADY_SOLD}) if it's in the sold set
//   - (nil, error) on internal failure
func (s *SeatService) HoldSeat(ctx context.Context, p HoldSeatParams) (*Hold, error) {
	if p.OccurrenceID == "" || p.SeatID == "" || p.UserID == "" {
		return nil, NewError(ErrCodeInvalidRequest, "missing required fields")
	}

	ttl := p.TTL
	if ttl <= 0 {
		ttl = s.defaultTTL
	}

	seatKey := redis.SeatHoldKey(p.OccurrenceID, p.SeatID)
	soldSetKey := redis.SoldSeatsSetKey(p.OccurrenceID)
	heldSetKey := redis.HeldSeatsSetKey(p.OccurrenceID)

	token := newToken()
	now := time.Now().UTC()
	hold := &Hold{
		Token:        token,
		Kind:         "seat",
		OccurrenceID: p.OccurrenceID,
		SeatID:       p.SeatID,
		SeatLabel:    p.SeatLabel,
		Section:      p.Section,
		Row:          p.Row,
		SeatNumber:   p.SeatNumber,
		Quantity:     1,
		UserID:       p.UserID,
		ExpiresAt:    now.Add(ttl),
		CreatedAt:    now,
	}

	payload, err := json.Marshal(hold)
	if err != nil {
		return nil, fmt.Errorf("marshal hold: %w", err)
	}

	// ─── Atomic hold: check sold, SET NX, add to held set ───
	result, err := s.scripts.HoldSeatAtomic.Run(
		ctx, s.rdb.Client,
		[]string{seatKey, soldSetKey, heldSetKey},
		p.SeatID, payload, int(ttl.Seconds()),
	).Int()
	if err != nil {
		return nil, fmt.Errorf("hold seat script: %w", err)
	}

	switch result {
	case 0:
		return nil, NewError(ErrCodeSeatAlreadySold, "seat is already sold")
	case -1:
		return nil, NewError(ErrCodeSeatAlreadyHeld, "seat is already held by another user")
	case 1:
		// success
	default:
		return nil, fmt.Errorf("unexpected hold script result: %d", result)
	}

	// ─── Store the token → key mapping ──────────────────
	// Token key carries a TTL buffer so the expiry subscriber can recover
	// this payload after the hold key itself expires.
	tokenPayload, _ := json.Marshal(map[string]any{
		"kind":          "seat",
		"occurrence_id": p.OccurrenceID,
		"seat_id":       p.SeatID,
		"user_id":       p.UserID,
	})
	tokenKey := redis.TokenKey(token)
	if err := s.rdb.Set(ctx, tokenKey, tokenPayload, ttl+redis.TokenTTLBuffer).Err(); err != nil {
		// Rollback both keys.
		_ = s.rdb.Del(ctx, seatKey).Err()
		_ = s.rdb.SRem(ctx, heldSetKey, p.SeatID).Err()
		return nil, fmt.Errorf("set token: %w", err)
	}

	s.log.Info("seat held",
		zap.String("occurrence_id", p.OccurrenceID),
		zap.String("seat_id", p.SeatID),
		zap.String("seat_label", p.SeatLabel),
		zap.String("user_id", p.UserID),
		zap.String("token", token),
		zap.Duration("ttl", ttl),
	)

	// ─── Emit seat.held event ───────────────────────────
	if s.publisher != nil {
		_ = s.publisher.Publish(
			ctx,
			TopicSeatHeld,
			p.OccurrenceID,
			"",
			SeatHeldPayload{
				EventOccurrenceID: p.OccurrenceID,
				SeatID:            p.SeatID,
				SeatLabel:         p.SeatLabel,
				Section:           p.Section,
				Row:               p.Row,
				SeatNumber:        p.SeatNumber,
				UserID:            p.UserID,
				HoldToken:         token,
				ExpiresAt:         hold.ExpiresAt,
			},
		)
	}

	return hold, nil
}

// ReleaseHoldParams carries the input to a ReleaseHold call.
type ReleaseHoldParams struct {
	Token  string
	UserID string
}

// ReleaseHold deletes a seat hold, but only if the caller owns it.
// Idempotent: releasing a non-existent hold returns nil (no error).
func (s *SeatService) ReleaseHold(ctx context.Context, p ReleaseHoldParams) error {
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
		SeatID       string `json:"seat_id"`
		UserID       string `json:"user_id"`
	}
	if err := json.Unmarshal([]byte(tokenPayload), &meta); err != nil {
		return fmt.Errorf("unmarshal token: %w", err)
	}

	if meta.Kind != "seat" {
		return NewError(ErrCodeInvalidRequest, "token is not for a seat hold")
	}

	seatKey := redis.SeatHoldKey(meta.OccurrenceID, meta.SeatID)
	heldSetKey := redis.HeldSeatsSetKey(meta.OccurrenceID)

	result, err := s.scripts.ReleaseSeatIfOwner.Run(
		ctx, s.rdb.Client,
		[]string{seatKey, heldSetKey},
		p.UserID,
	).Int()
	if err != nil {
		return fmt.Errorf("release script: %w", err)
	}

	_ = s.rdb.Del(ctx, tokenKey).Err()

	s.log.Info("seat released",
		zap.String("occurrence_id", meta.OccurrenceID),
		zap.String("seat_id", meta.SeatID),
		zap.String("user_id", p.UserID),
		zap.Int("script_result", result),
	)

	return nil
}

// ExtendHoldParams carries the input to an ExtendHold call.
type ExtendHoldParams struct {
	Token             string
	UserID            string
	AdditionalSeconds int
}

// ExtendHold adds time to an existing hold, capped by maxExtend.
func (s *SeatService) ExtendHold(ctx context.Context, p ExtendHoldParams) (time.Duration, error) {
	if p.Token == "" || p.UserID == "" {
		return 0, NewError(ErrCodeInvalidRequest, "missing token or user_id")
	}
	if p.AdditionalSeconds <= 0 {
		return 0, NewError(ErrCodeInvalidRequest, "additional_seconds must be positive")
	}

	maxSeconds := int(s.maxExtend.Seconds())
	if p.AdditionalSeconds > maxSeconds {
		p.AdditionalSeconds = maxSeconds
	}

	tokenKey := redis.TokenKey(p.Token)
	tokenPayload, err := s.rdb.Get(ctx, tokenKey).Result()
	if errors.Is(err, goredis.Nil) {
		return 0, NewError(ErrCodeHoldNotFound, "hold not found or expired")
	}
	if err != nil {
		return 0, fmt.Errorf("get token: %w", err)
	}

	var meta struct {
		Kind         string `json:"kind"`
		OccurrenceID string `json:"occurrence_id"`
		SeatID       string `json:"seat_id"`
	}
	if err := json.Unmarshal([]byte(tokenPayload), &meta); err != nil {
		return 0, fmt.Errorf("unmarshal token: %w", err)
	}
	if meta.Kind != "seat" {
		return 0, NewError(ErrCodeInvalidRequest, "token is not for a seat hold")
	}

	seatKey := redis.SeatHoldKey(meta.OccurrenceID, meta.SeatID)
	newTTL, err := s.scripts.ExtendSeatIfOwner.Run(
		ctx, s.rdb.Client,
		[]string{seatKey},
		p.UserID, p.AdditionalSeconds,
	).Int()
	if err != nil {
		return 0, fmt.Errorf("extend script: %w", err)
	}

	switch newTTL {
	case -1:
		return 0, NewError(ErrCodeHoldNotOwner, "hold is not owned by caller")
	case -2:
		return 0, NewError(ErrCodeHoldNotFound, "hold expired")
	}

	// Extend the token key too, keeping the buffer ahead of the hold.
	tokenTTL := time.Duration(newTTL)*time.Second + redis.TokenTTLBuffer
	_ = s.rdb.Expire(ctx, tokenKey, tokenTTL).Err()

	s.log.Info("seat hold extended",
		zap.String("occurrence_id", meta.OccurrenceID),
		zap.String("seat_id", meta.SeatID),
		zap.Int("additional_seconds", p.AdditionalSeconds),
		zap.Int("new_ttl_seconds", newTTL),
	)

	return time.Duration(newTTL) * time.Second, nil
}

// ConfirmHoldParams carries the input to a ConfirmHold call.
type ConfirmHoldParams struct {
	Token   string
	UserID  string
	OrderID string
}

// ConfirmHold converts a hold to SOLD. Called after payment succeeds.
// Idempotent: confirming an already-confirmed hold returns nil.
func (s *SeatService) ConfirmHold(ctx context.Context, p ConfirmHoldParams) error {
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
		SeatID       string `json:"seat_id"`
	}
	if err := json.Unmarshal([]byte(tokenPayload), &meta); err != nil {
		return fmt.Errorf("unmarshal token: %w", err)
	}
	if meta.Kind != "seat" {
		return NewError(ErrCodeInvalidRequest, "token is not for a seat hold")
	}

	seatKey := redis.SeatHoldKey(meta.OccurrenceID, meta.SeatID)
	soldSetKey := redis.SoldSeatsSetKey(meta.OccurrenceID)
	heldSetKey := redis.HeldSeatsSetKey(meta.OccurrenceID)

	result, err := s.scripts.ConfirmSeatIfOwner.Run(
		ctx, s.rdb.Client,
		[]string{seatKey, soldSetKey, heldSetKey},
		p.UserID,
	).Int()
	if err != nil {
		return fmt.Errorf("confirm script: %w", err)
	}

	_ = s.rdb.Del(ctx, tokenKey).Err()

	if result != 1 {
		return NewError(ErrCodeHoldNotOwner, "hold is not owned by caller")
	}

	s.log.Info("seat confirmed (sold)",
		zap.String("occurrence_id", meta.OccurrenceID),
		zap.String("seat_id", meta.SeatID),
		zap.String("user_id", p.UserID),
		zap.String("order_id", p.OrderID),
	)

	// ─── Emit seat.sold event ───────────────────────────
	if s.publisher != nil {
		_ = s.publisher.Publish(
			ctx,
			TopicSeatSold,
			meta.OccurrenceID,
			"",
			SeatSoldPayload{
				EventOccurrenceID: meta.OccurrenceID,
				SeatID:            meta.SeatID,
				SeatLabel:         "",
				OrderID:           p.OrderID,
				UserID:            p.UserID,
				SoldAt:            time.Now().UTC(),
				PriceCents:        0,
				Currency:          "USD",
			},
		)
	}

	return nil
}

// CleanupSmokeTest removes all Redis state for a given occurrence.
// Used ONLY by the smoke test — not exposed in production.
func (s *SeatService) CleanupSmokeTest(ctx context.Context, occurrenceID string) error {
	soldKey := redis.SoldSeatsSetKey(occurrenceID)
	heldKey := redis.HeldSeatsSetKey(occurrenceID)

	if err := s.rdb.Del(ctx, soldKey).Err(); err != nil {
		return fmt.Errorf("delete sold set: %w", err)
	}
	if err := s.rdb.Del(ctx, heldKey).Err(); err != nil {
		return fmt.Errorf("delete held set: %w", err)
	}

	// Find and delete any remaining seat hold keys.
	pattern := fmt.Sprintf("omnitix:seat:%s:seat:*", occurrenceID)
	keys, err := s.rdb.Keys(ctx, pattern).Result()
	if err != nil {
		return fmt.Errorf("scan seat keys: %w", err)
	}
	if len(keys) > 0 {
		if err := s.rdb.Del(ctx, keys...).Err(); err != nil {
			return fmt.Errorf("delete seat keys: %w", err)
		}
	}

	// Delete tokens pointing at this occurrence.
	// Note: KEYS pattern is acceptable here because this is a smoke-test path.
	tokenKeys, err := s.rdb.Keys(ctx, "omnitix:token:*").Result()
	if err != nil {
		return fmt.Errorf("scan token keys: %w", err)
	}
	for _, tk := range tokenKeys {
		val, err := s.rdb.Get(ctx, tk).Result()
		if err != nil {
			continue
		}
		if strings.Contains(val, occurrenceID) {
			_ = s.rdb.Del(ctx, tk).Err()
		}
	}

	s.log.Info("smoke cleanup complete", zap.String("occurrence_id", occurrenceID))
	return nil
}

// RawDel deletes a key directly. Used only for smoke test cleanup and
// admin operations — not exposed via the public API.
func (s *SeatService) RawDel(ctx context.Context, key string) error {
	return s.rdb.Del(ctx, key).Err()
}