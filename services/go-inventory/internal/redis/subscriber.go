// Package redis — keyspace notification subscriber.
//
// Redis can be configured (see infra/redis/redis.conf) to publish events on
// `__keyevent@<db>__:expired` whenever a key expires. We use this to detect
// seat-hold and GA-hold TTLs elapsing, and to publish the corresponding
// `seat.expired` / `ga.expired` events to Kafka.
//
// # Why token keys outlive hold keys
//
// A seat hold has two Redis keys:
//
//	omnitix:seat:{occ}:seat:{seat}  ← the hold; carries the TTL
//	omnitix:token:{token}           ← the token → hold mapping
//
// If both expire at exactly the same instant, the subscriber cannot look up
// the token payload to build the expired event. To guarantee the payload is
// available, the token key is given a small TTL buffer (TokenTTLBuffer) on
// top of the hold TTL. See `seat.go` and `ga.go` for the write side.
//
// # Atomicity
//
// Redis does not guarantee event delivery. Keyspace notifications are
// fire-and-forget. A restart of the subscriber during an expiry means that
// particular expiration event is lost. This is acceptable for v1 because:
//   - The seat remains AVAILABLE in Redis regardless.
//   - The "loss" only affects viewers already connected via WebSocket, who
//     will get a fresh snapshot on their next reconnect.
//   - A reconciliation pass (Laravel `omnitix:reconcile-holds`) sweeps stale
//     orders every 2 minutes.
//
// v2 can use Redis Streams for at-least-once delivery if the audit trail
// needs to be complete.
package redis

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/omnitix/go-inventory/internal/logger"
	goredis "github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

// TokenTTLBuffer is added to the token key's TTL on top of the hold TTL.
// This guarantees the token payload is still present when the hold key's
// expiration event arrives.
//
// Set generously: a 30s buffer costs almost nothing in memory and eliminates
// the race entirely.
const TokenTTLBuffer = 30 * time.Second

// ExpiryHandler is called when a hold key expires. The caller (typically the
// seat or GA service) is responsible for publishing the Kafka event.
type ExpiryHandler interface {
	// OnSeatExpired is called when a seat hold key expires.
	// The handler must look up the token payload from Redis (it's still there).
	OnSeatExpired(ctx context.Context, occurrenceID, seatID string) error

	// OnGaExpired is called when a GA held-counter key expires.
	OnGaExpired(ctx context.Context, occurrenceID, zoneID string) error
}

// Subscriber listens for Redis key expiry events and dispatches them.
type Subscriber struct {
	rdb     *Client
	log     *logger.Logger
	handler ExpiryHandler

	keyPrefix string
	db        int

	ctx    context.Context
	cancel context.CancelFunc
	wg     sync.WaitGroup

	started bool
	mu      sync.Mutex
}

// NewSubscriber constructs a keyspace-notification subscriber.
func NewSubscriber(rdb *Client, handler ExpiryHandler, log *logger.Logger) *Subscriber {
	ctx, cancel := context.WithCancel(context.Background())
	return &Subscriber{
		rdb:       rdb,
		log:       log.Named("expiry_subscriber"),
		handler:   handler,
		keyPrefix: prefix + ":",
		db:        rdb.Options().DB,
		ctx:       ctx,
		cancel:    cancel,
	}
}

// Start begins the subscription loop. Non-blocking — the loop runs in a
// goroutine. Call Stop() to shut down cleanly.
func (s *Subscriber) Start() error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.started {
		return fmt.Errorf("subscriber already started")
	}

	channel := fmt.Sprintf("__keyevent@%d__:expired", s.db)

	pubsub := s.rdb.Subscribe(s.ctx, channel)

	subscribeCtx, cancel := context.WithTimeout(s.ctx, 5*time.Second)
	defer cancel()
	if _, err := pubsub.Receive(subscribeCtx); err != nil {
		_ = pubsub.Close()
		return fmt.Errorf("subscribe to %s: %w", channel, err)
	}

	s.log.Info("subscribed to keyspace notifications",
		zap.String("channel", channel),
		zap.String("key_prefix", s.keyPrefix),
	)

	s.wg.Add(1)
	go s.run(pubsub)

	s.started = true
	return nil
}

// Stop gracefully shuts down the subscriber.
func (s *Subscriber) Stop() {
	s.mu.Lock()
	if !s.started {
		s.mu.Unlock()
		return
	}
	s.started = false
	s.mu.Unlock()

	s.cancel()
	s.wg.Wait()
	s.log.Info("subscriber stopped")
}

func (s *Subscriber) run(pubsub *goredis.PubSub) {
	defer func() { _ = pubsub.Close() }()
	defer s.wg.Done()

	ch := pubsub.Channel()

	for {
		select {
		case <-s.ctx.Done():
			s.log.Debug("subscriber context cancelled")
			return
		case msg, ok := <-ch:
			if !ok {
				s.log.Warn("pub/sub channel closed")
				return
			}
			s.dispatch(msg.Payload)
		}
	}
}

// dispatch parses an expired key and routes it to the right handler.
//
// Key shapes:
//
//	omnitix:seat:{occurrenceID}:seat:{seatID}
//	omnitix:ga:{occurrenceID}:zone:{zoneID}:held
func (s *Subscriber) dispatch(key string) {
	if !strings.HasPrefix(key, s.keyPrefix) {
		return
	}

	switch {
	case strings.HasPrefix(key, prefix+":seat:"):
		s.dispatchSeat(key)
	case strings.HasPrefix(key, prefix+":ga:"):
		s.dispatchGA(key)
	default:
		s.log.Debug("ignoring expired key", zap.String("key", key))
	}
}

func (s *Subscriber) dispatchSeat(key string) {
	parts := strings.SplitN(key, ":", 5)
	if len(parts) != 5 || parts[3] != "seat" {
		s.log.Warn("malformed seat key", zap.String("key", key))
		return
	}
	occurrenceID := parts[2]
	seatID := parts[4]

	s.log.Info("seat hold expired",
		zap.String("occurrence_id", occurrenceID),
		zap.String("seat_id", seatID),
	)

	ctx, cancel := context.WithTimeout(s.ctx, 5*time.Second)
	defer cancel()
	if err := s.handler.OnSeatExpired(ctx, occurrenceID, seatID); err != nil {
		s.log.Error("failed to handle seat expiry",
			zap.String("occurrence_id", occurrenceID),
			zap.String("seat_id", seatID),
			zap.Error(err),
		)
	}
}

func (s *Subscriber) dispatchGA(key string) {
	parts := strings.SplitN(key, ":", 6)
	if len(parts) != 6 || parts[3] != "zone" || parts[5] != "held" {
		s.log.Warn("malformed GA key", zap.String("key", key))
		return
	}
	occurrenceID := parts[2]
	zoneID := parts[4]

	s.log.Info("GA hold expired",
		zap.String("occurrence_id", occurrenceID),
		zap.String("ga_zone_id", zoneID),
	)

	ctx, cancel := context.WithTimeout(s.ctx, 5*time.Second)
	defer cancel()
	if err := s.handler.OnGaExpired(ctx, occurrenceID, zoneID); err != nil {
		s.log.Error("failed to handle GA expiry",
			zap.String("occurrence_id", occurrenceID),
			zap.String("ga_zone_id", zoneID),
			zap.Error(err),
		)
	}
}

// ─── Token payload recovery ─────────────────────────────────

// TokenPayload mirrors the JSON blob written by seat.go / ga.go into
// `omnitix:token:{token}`.
type TokenPayload struct {
	Kind         string `json:"kind"` // "seat" | "ga"
	OccurrenceID string `json:"occurrence_id"`
	SeatID       string `json:"seat_id,omitempty"`
	GaZoneID     string `json:"ga_zone_id,omitempty"`
	UserID       string `json:"user_id"`
	Quantity     int    `json:"quantity,omitempty"`
}

// RecoverTokenPayloadByOccurrenceSeat finds the token that refers to the
// given (occurrenceID, seatID).
//
// NOTE: this is a SCAN-based lookup and is O(tokens). For v1's concurrency
// envelope (a few thousand concurrent holds per DB) this is fine. v2 should
// maintain `omnitix:seat-token:{occ}:{seat}` as a direct reverse index.
func (c *Client) RecoverTokenPayloadByOccurrenceSeat(
	ctx context.Context,
	occurrenceID, seatID string,
) (*TokenPayload, string, error) {
	pattern := fmt.Sprintf("%s:token:*", prefix)

	iter := c.Scan(ctx, 0, pattern, 200).Iterator()
	for iter.Next(ctx) {
		tokenKey := iter.Val()

		raw, err := c.Get(ctx, tokenKey).Bytes()
		if err != nil {
			continue
		}

		var tp TokenPayload
		if err := json.Unmarshal(raw, &tp); err != nil {
			continue
		}

		if tp.Kind == "seat" &&
			tp.OccurrenceID == occurrenceID &&
			tp.SeatID == seatID {
			return &tp, strings.TrimPrefix(tokenKey, prefix+":token:"), nil
		}
	}
	if err := iter.Err(); err != nil {
		return nil, "", fmt.Errorf("scan tokens: %w", err)
	}

	return nil, "", fmt.Errorf("no token found for seat %s in occurrence %s",
		seatID, occurrenceID)
}

// RecoverGaTokenPayload finds a token associated with a GA zone. Because
// multiple users can hold the same GA zone concurrently, this is ambiguous —
// we return the first matching token. The GA held-counter key expires only
// when there are zero held units, so this path is essentially unreachable.
func (c *Client) RecoverGaTokenPayload(
	ctx context.Context,
	occurrenceID, zoneID string,
) (*TokenPayload, string, error) {
	pattern := fmt.Sprintf("%s:token:*", prefix)

	iter := c.Scan(ctx, 0, pattern, 200).Iterator()
	for iter.Next(ctx) {
		tokenKey := iter.Val()

		raw, err := c.Get(ctx, tokenKey).Bytes()
		if err != nil {
			continue
		}

		var tp TokenPayload
		if err := json.Unmarshal(raw, &tp); err != nil {
			continue
		}

		if tp.Kind == "ga" &&
			tp.OccurrenceID == occurrenceID &&
			tp.GaZoneID == zoneID {
			return &tp, strings.TrimPrefix(tokenKey, prefix+":token:"), nil
		}
	}
	if err := iter.Err(); err != nil {
		return nil, "", fmt.Errorf("scan tokens: %w", err)
	}

	return nil, "", fmt.Errorf("no token found for GA zone %s in occurrence %s",
		zoneID, occurrenceID)
}