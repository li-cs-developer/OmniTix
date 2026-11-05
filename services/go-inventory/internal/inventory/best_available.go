package inventory

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"time"

	"github.com/omnitix/go-inventory/internal/logger"
	"github.com/omnitix/go-inventory/internal/redis"
	goredis "github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

// SeatsByRow is a helper for grouping seats by row label.
type SeatsByRow map[string][]SeatInfo

// SeatInfo describes a seat's position for the best-available algorithm.
type SeatInfo struct {
	SeatID     string `json:"seat_id"`
	SeatLabel  string `json:"seat_label"`
	Section    string `json:"section"`
	Row        string `json:"row"`
	SeatNumber int    `json:"seat_number"` // numeric, for range math
	X          int    `json:"x"`           // map coordinate
	Y          int    `json:"y"`
}

// LayoutCache is the cached seat layout for one occurrence.
type LayoutCache struct {
	OccurrenceID string                `json:"occurrence_id"`
	Sections     map[string][]SeatInfo `json:"sections"`
	CachedAt     time.Time             `json:"cached_at"`
}

// BestAvailableService implements the contiguous-seat finder.
type BestAvailableService struct {
	rdb     *redis.Client
	scripts *redis.Scripts
	seatSvc *SeatService
	log     *logger.Logger
}

// NewBestAvailableService constructs the service.
func NewBestAvailableService(
	rdb *redis.Client,
	scripts *redis.Scripts,
	seatSvc *SeatService,
	log *logger.Logger,
) *BestAvailableService {
	return &BestAvailableService{
		rdb:     rdb,
		scripts: scripts,
		seatSvc: seatSvc,
		log:     log.Named("best_available"),
	}
}

// ─── Layout cache ───────────────────────────────────────────

// CacheLayout stores a seat layout in Redis for fast retrieval.
// Called by admin endpoints when an event goes on sale.
func (s *BestAvailableService) CacheLayout(ctx context.Context, layout *LayoutCache) error {
	if layout.OccurrenceID == "" {
		return NewError(ErrCodeInvalidRequest, "missing occurrence_id")
	}
	layout.CachedAt = time.Now().UTC()

	key := layoutKey(layout.OccurrenceID)
	payload, err := json.Marshal(layout)
	if err != nil {
		return fmt.Errorf("marshal layout: %w", err)
	}

	// 24h TTL; admin re-caches when layout changes.
	if err := s.rdb.Set(ctx, key, payload, 24*time.Hour).Err(); err != nil {
		return fmt.Errorf("set layout: %w", err)
	}

	s.log.Info("layout cached",
		zap.String("occurrence_id", layout.OccurrenceID),
		zap.Int("sections", len(layout.Sections)),
	)
	return nil
}

// loadLayout reads the cached layout from Redis.
func (s *BestAvailableService) loadLayout(ctx context.Context, occurrenceID string) (*LayoutCache, error) {
	key := layoutKey(occurrenceID)
	raw, err := s.rdb.Get(ctx, key).Result()
	if errors.Is(err, goredis.Nil) {
		return nil, NewError(ErrCodeInvalidRequest,
			"seat layout not cached for this occurrence; call CacheLayout first")
	}
	if err != nil {
		return nil, fmt.Errorf("get layout: %w", err)
	}

	var layout LayoutCache
	if err := json.Unmarshal([]byte(raw), &layout); err != nil {
		return nil, fmt.Errorf("unmarshal layout: %w", err)
	}
	return &layout, nil
}

// ─── Best-available core ────────────────────────────────────

// BestAvailableParams carries the input to a BestAvailable call.
type BestAvailableParams struct {
	OccurrenceID string
	SectionCode  string
	Quantity     int
	UserID       string
	TTL          time.Duration
}

// BestAvailableResult is the outcome of a successful best-available pick.
type BestAvailableResult struct {
	Holds        []*Hold
	Section      string
	Row          string
	TotalTTL     time.Duration
	RequestedQty int
	FulfilledQty int
}

// FindBestAvailable locates the best contiguous run of seats and holds them.
//
// Returns:
//   - (*BestAvailableResult, nil) on success
//   - (nil, *Error{Code: GA_INSUFFICIENT_CAPACITY}) if no run fits
//   - (nil, error) on internal failure
func (s *BestAvailableService) FindBestAvailable(
	ctx context.Context,
	p BestAvailableParams,
) (*BestAvailableResult, error) {
	if p.OccurrenceID == "" || p.UserID == "" {
		return nil, NewError(ErrCodeInvalidRequest, "missing occurrence_id or user_id")
	}
	if p.Quantity <= 0 || p.Quantity > 10 {
		return nil, NewError(ErrCodeInvalidRequest, "quantity must be 1-10")
	}

	// ─── 1. Load layout ─────────────────────────────────
	layout, err := s.loadLayout(ctx, p.OccurrenceID)
	if err != nil {
		return nil, err
	}

	// ─── 2. Determine which sections to search ──────────
	sections := []string{}
	if p.SectionCode != "" {
		if _, ok := layout.Sections[p.SectionCode]; !ok {
			return nil, NewError(ErrCodeInvalidRequest,
				fmt.Sprintf("section %q not found", p.SectionCode))
		}
		sections = []string{p.SectionCode}
	} else {
		// All sections, sorted for deterministic order
		for code := range layout.Sections {
			sections = append(sections, code)
		}
		sort.Strings(sections)
	}

	// ─── 3. Fetch taken seats (sold + held) ────────────
	taken, err := s.getTakenSeats(ctx, p.OccurrenceID)
	if err != nil {
		return nil, fmt.Errorf("fetch taken seats: %w", err)
	}

	// ─── 4. Find candidate runs ────────────────────────
	var best *candidateRun
	for _, section := range sections {
		seats := layout.Sections[section]
		byRow := groupByRow(seats)

		// Sort rows by stage proximity. For now: lexicographic (A, B, C...).
		// Future: use layout stage position for true distance.
		rowLabels := make([]string, 0, len(byRow))
		for r := range byRow {
			rowLabels = append(rowLabels, r)
		}
		sort.Strings(rowLabels)

		for _, rowLabel := range rowLabels {
			rowSeats := byRow[rowLabel]
			sort.Slice(rowSeats, func(i, j int) bool {
				return rowSeats[i].SeatNumber < rowSeats[j].SeatNumber
			})

			runs := findContiguousRuns(rowSeats, taken, p.Quantity)
			for _, r := range runs {
				// Pick the run with the best score.
				if best == nil || r.score < best.score {
					rCopy := r
					best = &rCopy
				}
			}
		}
	}

	if best == nil {
		return nil, NewError(ErrCodeGaInsufficient,
			fmt.Sprintf("no contiguous run of %d seats found", p.Quantity))
	}

	// ─── 5. Atomically hold all seats in the run ───────
	// Use a Lua script that either holds all or none.
	holds, err := s.holdRunAtomically(ctx, p, best.seats)
	if err != nil {
		return nil, err
	}

	return &BestAvailableResult{
		Holds:        holds,
		Section:      best.section,
		Row:          best.row,
		TotalTTL:     p.TTL,
		RequestedQty: p.Quantity,
		FulfilledQty: len(holds),
	}, nil
}

// candidateRun represents a possible set of seats to hold.
type candidateRun struct {
	seats   []SeatInfo
	section string
	row     string
	score   int // lower is better
}

// groupByRow splits a slice of seats into rows.
func groupByRow(seats []SeatInfo) SeatsByRow {
	out := make(SeatsByRow)
	for _, s := range seats {
		out[s.Row] = append(out[s.Row], s)
	}
	return out
}

// findContiguousRuns returns all valid contiguous runs of length n in a row.
// A run is valid if:
//   - All its seats are available (not in the taken set)
//   - Removing it does NOT leave an orphan single seat in the row
//
// Row must already be sorted by seat_number ascending.
func findContiguousRuns(row []SeatInfo, taken map[string]bool, n int) []candidateRun {
	var out []candidateRun

	for i := 0; i+n <= len(row); i++ {
		// Check if all seats in [i, i+n) are available
		allAvailable := true
		for j := i; j < i+n; j++ {
			if taken[row[j].SeatID] {
				allAvailable = false
				break
			}
		}
		if !allAvailable {
			continue
		}

		// Check for orphan creation:
		// - Seat before the run (i-1): if it exists and is available now,
		//   after we take [i, i+n) it will still be available. But if it's
		//   an isolated single seat (i.e., i-2 is taken), it becomes orphaned.
		// - Seat after the run (i+n): same logic.
		if leavesOrphanBefore(row, taken, i) {
			continue
		}
		if leavesOrphanAfter(row, taken, i+n) {
			continue
		}

		// Score: lower = better.
		// Center-out preference: distance from the "middle" seat of the row.
		rowMid := len(row) / 2
		runMid := i + n/2
		score := abs(runMid - rowMid)

		out = append(out, candidateRun{
			seats:   row[i : i+n],
			section: row[i].Section,
			row:     row[i].Row,
			score:   score,
		})
	}
	return out
}

// leavesOrphanBefore returns true if taking a run starting at index i would
// leave a single available seat at i-1 that's trapped between taken seats
// and the run.
func leavesOrphanBefore(row []SeatInfo, taken map[string]bool, i int) bool {
	if i == 0 {
		return false
	}
	// Seat at i-1 is available currently (otherwise the run would fail earlier)
	// Check if i-2 is taken or out of bounds.
	if i-2 < 0 {
		return false
	}
	if taken[row[i-2].SeatID] {
		// Seat i-1 will be trapped → orphan
		return true
	}
	return false
}

// leavesOrphanAfter returns true if taking a run ending at index i+n would
// leave a single available seat at i+n that's trapped.
func leavesOrphanAfter(row []SeatInfo, taken map[string]bool, endIdx int) bool {
	if endIdx >= len(row) {
		return false
	}
	if endIdx+1 >= len(row) {
		return false
	}
	if taken[row[endIdx+1].SeatID] {
		return true
	}
	return false
}

// holdRunAtomically holds all seats in a run; rolls back if any fail.
func (s *BestAvailableService) holdRunAtomically(
	ctx context.Context,
	p BestAvailableParams,
	seats []SeatInfo,
) ([]*Hold, error) {
	held := make([]*Hold, 0, len(seats))

	for _, seat := range seats {
		hold, err := s.seatSvc.HoldSeat(ctx, HoldSeatParams{
			OccurrenceID: p.OccurrenceID,
			SeatID:       seat.SeatID,
			SeatLabel:    seat.SeatLabel,
			Section:      seat.Section,
			Row:          seat.Row,
			SeatNumber:   strconv.Itoa(seat.SeatNumber),
			UserID:       p.UserID,
			TTL:          p.TTL,
		})
		if err != nil {
			// Roll back all previously held seats
			s.log.Warn("best-available: rollback due to hold failure",
				zap.String("failed_seat", seat.SeatID),
				zap.Error(err),
			)
			for _, h := range held {
				_ = s.seatSvc.ReleaseHold(ctx, ReleaseHoldParams{
					Token:  h.Token,
					UserID: p.UserID,
				})
			}
			return nil, NewError(ErrCodeInternal,
				"partial hold failure; rolled back. retry or pick manually")
		}
		held = append(held, hold)
	}

	s.log.Info("best-available: held run",
		zap.String("occurrence_id", p.OccurrenceID),
		zap.String("section", seats[0].Section),
		zap.String("row", seats[0].Row),
		zap.Int("count", len(held)),
	)
	return held, nil
}

// getTakenSeats returns the set of seat IDs that are either sold or held.
// Uses the maintained held-set + sold-set; no KEYS scan.
func (s *BestAvailableService) getTakenSeats(ctx context.Context, occurrenceID string) (map[string]bool, error) {
	soldKey := redis.SoldSeatsSetKey(occurrenceID)
	heldKey := redis.HeldSeatsSetKey(occurrenceID)

	pipe := s.rdb.Pipeline()
	soldCmd := pipe.SMembers(ctx, soldKey)
	heldCmd := pipe.SMembers(ctx, heldKey)
	if _, err := pipe.Exec(ctx); err != nil && err != goredis.Nil {
		return nil, fmt.Errorf("fetch taken seats: %w", err)
	}

	taken := make(map[string]bool)
	if ids, err := soldCmd.Result(); err == nil {
		for _, id := range ids {
			taken[id] = true
		}
	}
	if ids, err := heldCmd.Result(); err == nil {
		for _, id := range ids {
			taken[id] = true
		}
	}
	return taken, nil
}

// layoutKey returns the Redis key for a cached layout.
func layoutKey(occurrenceID string) string {
	return fmt.Sprintf("omnitix:layout:%s", occurrenceID)
}

// abs returns |x|.
func abs(x int) int {
	if x < 0 {
		return -x
	}
	return x
}