// Package inventory implements the OmniTix atomic hold engine for seats
// and general admission zones.
package inventory

import (
	"time"
	"context"
)

// Hold represents an active seat or GA hold. It's the internal representation
// that gets serialized into Redis and returned to callers.
type Hold struct {
	Token            string    `json:"token"`
	Kind             string    `json:"kind"`               // "seat" | "ga"
	OccurrenceID     string    `json:"occurrence_id"`
	SeatID           string    `json:"seat_id,omitempty"`
	SeatLabel        string    `json:"seat_label,omitempty"`
	Section          string    `json:"section,omitempty"`
	Row              string    `json:"row,omitempty"`
	SeatNumber       string    `json:"seat_number,omitempty"`
	GaZoneID         string    `json:"ga_zone_id,omitempty"`
	GaZoneName       string    `json:"ga_zone_name,omitempty"`
	Quantity         int       `json:"quantity"`
	UserID           string    `json:"user_id"`
	ExpiresAt        time.Time `json:"expires_at"`
	CreatedAt        time.Time `json:"created_at"`
}

// SeatState mirrors the proto enum but lives natively in Go.
type SeatState string

const (
	SeatStateAvailable   SeatState = "available"
	SeatStateHeld        SeatState = "held"
	SeatStateSold        SeatState = "sold"
	SeatStateBlocked     SeatState = "blocked"
	SeatStateUnavailable SeatState = "unavailable"
)

// HoldResult is the outcome of a successful hold attempt.
type HoldResult struct {
	Hold *Hold
}

// Error codes — stable strings that map to proto Result.error_code.
const (
	ErrCodeSeatAlreadyHeld  = "SEAT_ALREADY_HELD"
	ErrCodeSeatAlreadySold  = "SEAT_ALREADY_SOLD"
	ErrCodeGaInsufficient   = "GA_INSUFFICIENT_CAPACITY"
	ErrCodeHoldNotFound     = "HOLD_NOT_FOUND"
	ErrCodeHoldNotOwner     = "HOLD_NOT_OWNER"
	ErrCodeGaCapacityMissing = "GA_CAPACITY_MISSING"
	ErrCodeInvalidRequest   = "INVALID_REQUEST"
	ErrCodeInternal         = "INTERNAL_ERROR"
)

// Sentinel errors for clean error propagation.
type Error struct {
	Code    string
	Message string
}

func (e *Error) Error() string { return e.Code + ": " + e.Message }

func NewError(code, message string) *Error {
	return &Error{Code: code, Message: message}
}

// Publisher emits inventory events to the event bus. The Kafka producer
// implements this; tests can pass a no-op or spy.
type Publisher interface {
	Publish(ctx context.Context, topicSuffix, partitionKey, correlationID string, payload any) error
}