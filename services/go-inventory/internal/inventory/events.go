package inventory

import "time"

// ─── Seat events ────────────────────────────────────────────

// SeatHeldPayload is the body of a seat.held event.
type SeatHeldPayload struct {
	EventOccurrenceID string    `json:"event_occurrence_id"`
	SeatID            string    `json:"seat_id"`
	SeatLabel         string    `json:"seat_label"`
	Section           string    `json:"section"`
	Row               string    `json:"row"`
	SeatNumber        string    `json:"seat_number"`
	UserID            string    `json:"user_id"`
	HoldToken         string    `json:"hold_token"`
	ExpiresAt         time.Time `json:"expires_at"`
}

// SeatExpiredPayload is the body of a seat.expired event.
type SeatExpiredPayload struct {
	EventOccurrenceID   string `json:"event_occurrence_id"`
	SeatID              string `json:"seat_id"`
	SeatLabel           string `json:"seat_label"`
	UserID              string `json:"user_id"`
	HoldToken           string `json:"hold_token"`
	HeldDurationSeconds int    `json:"held_duration_seconds,omitempty"`
}

// SeatSoldPayload is the body of a seat.sold event.
type SeatSoldPayload struct {
	EventOccurrenceID string    `json:"event_occurrence_id"`
	SeatID            string    `json:"seat_id"`
	SeatLabel         string    `json:"seat_label"`
	OrderID           string    `json:"order_id"`
	UserID            string    `json:"user_id"`
	SoldAt            time.Time `json:"sold_at"`
	PriceCents        int64     `json:"price_cents"`
	Currency          string    `json:"currency"`
}

// ─── GA events ──────────────────────────────────────────────

// GaHeldPayload is the body of a ga.held event.
type GaHeldPayload struct {
	EventOccurrenceID string    `json:"event_occurrence_id"`
	GaZoneID          string    `json:"ga_zone_id"`
	ZoneName          string    `json:"zone_name"`
	Quantity          int       `json:"quantity"`
	UserID            string    `json:"user_id"`
	HoldToken         string    `json:"hold_token"`
	ExpiresAt         time.Time `json:"expires_at"`
	RemainingCapacity int       `json:"remaining_capacity"`
}

// GaExpiredPayload is the body of a ga.expired event.
type GaExpiredPayload struct {
	EventOccurrenceID   string `json:"event_occurrence_id"`
	GaZoneID            string `json:"ga_zone_id"`
	Quantity            int    `json:"quantity"`
	UserID              string `json:"user_id"`
	HoldToken           string `json:"hold_token"`
	HeldDurationSeconds int    `json:"held_duration_seconds,omitempty"`
	RemainingCapacity   int    `json:"remaining_capacity"`
}

// GaSoldPayload is the body of a ga.sold event.
type GaSoldPayload struct {
	EventOccurrenceID string    `json:"event_occurrence_id"`
	GaZoneID          string    `json:"ga_zone_id"`
	Quantity          int       `json:"quantity"`
	OrderID           string    `json:"order_id"`
	UserID            string    `json:"user_id"`
	SoldAt            time.Time `json:"sold_at"`
	TotalPriceCents   int64     `json:"total_price_cents"`
	Currency          string    `json:"currency"`
}

// ─── Topic suffix constants ─────────────────────────────────

const (
	TopicSeatHeld    = "seat.held"
	TopicSeatExpired = "seat.expired"
	TopicSeatSold    = "seat.sold"
	TopicGaHeld      = "ga.held"
	TopicGaExpired   = "ga.expired"
	TopicGaSold      = "ga.sold"
)