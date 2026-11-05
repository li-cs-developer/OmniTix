package redis

import "fmt"

// Key prefixes and format strings.
//
// Naming convention: <namespace>:<scope>:<id>[:<subscope>[:<id>]]
//
// All keys are versioned implicitly through the `omnitix:` prefix — if we
// ever need a breaking change, we bump to `omnitix2:`.
const (
	prefix = "omnitix"

	// Seat hold keys.
	// Example: omnitix:seat:01H...:seat:01H...
	//          ^prefix  ^occ   ^seat
	seatHoldFmt = prefix + ":seat:%s:seat:%s"

	// Sold seat sets (one SET per occurrence).
	// Example: omnitix:seat:01H...:sold
	soldSeatsSetFmt = prefix + ":seat:%s:sold"

	// Held seat sets (one SET per occurrence, optional optimization).
	heldSeatsSetFmt = prefix + ":seat:%s:held"

	// GA zone counters.
	// Example: omnitix:ga:01H...:zone:01H...:held
	gaHeldFmt     = prefix + ":ga:%s:zone:%s:held"
	gaSoldFmt     = prefix + ":ga:%s:zone:%s:sold"
	gaCapacityFmt = prefix + ":ga:%s:zone:%s:capacity"

	// Hold token → owning key mapping.
	// Example: omnitix:token:01H...
	tokenFmt = prefix + ":token:%s"

	// Idempotency keys for repeated client requests.
	// Example: omnitix:idem:hold:abc123
	idemFmt = prefix + ":idem:%s:%s"
)

// ─── Seat keys ──────────────────────────────────────────────

// SeatHoldKey returns the Redis key for a specific seat hold.
func SeatHoldKey(occurrenceID, seatID string) string {
	return fmt.Sprintf(seatHoldFmt, occurrenceID, seatID)
}

// SoldSeatsSetKey returns the Redis key for the set of sold seats in an occurrence.
func SoldSeatsSetKey(occurrenceID string) string {
	return fmt.Sprintf(soldSeatsSetFmt, occurrenceID)
}

// HeldSeatsSetKey returns the Redis key for the set of held seats in an occurrence.
func HeldSeatsSetKey(occurrenceID string) string {
	return fmt.Sprintf(heldSeatsSetFmt, occurrenceID)
}

// ─── GA zone keys ───────────────────────────────────────────

func GaHeldKey(occurrenceID, zoneID string) string {
	return fmt.Sprintf(gaHeldFmt, occurrenceID, zoneID)
}

func GaSoldKey(occurrenceID, zoneID string) string {
	return fmt.Sprintf(gaSoldFmt, occurrenceID, zoneID)
}

func GaCapacityKey(occurrenceID, zoneID string) string {
	return fmt.Sprintf(gaCapacityFmt, occurrenceID, zoneID)
}

// ─── Token keys ─────────────────────────────────────────────

// TokenKey returns the Redis key for a hold token lookup.
func TokenKey(token string) string {
	return fmt.Sprintf(tokenFmt, token)
}

// ─── Idempotency keys ───────────────────────────────────────

// IdempotencyKey returns a namespaced idempotency key.
// scope: "hold", "release", "confirm", "charge"
func IdempotencyKey(scope, clientKey string) string {
	return fmt.Sprintf(idemFmt, scope, clientKey)
}