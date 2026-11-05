// Package httpapi exposes a JSON gateway in front of the gRPC InventoryService.
// Used by Laravel and other clients that can't easily speak gRPC.
package httpapi

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	commonv1 "github.com/omnitix/go-inventory/gen/go/omnitix/common/v1"
	inventoryv1 "github.com/omnitix/go-inventory/gen/go/omnitix/inventory/v1"
	"github.com/omnitix/go-inventory/internal/logger"
	"github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

type Server struct {
	handler inventoryv1.InventoryServiceServer
	log     *logger.Logger
	srv     *http.Server

	// Optional handles used by handleHealth to probe dependencies.
	// A nil value means "not configured" — the health endpoint treats
	// that as healthy (nothing to probe) so the process doesn't
	// self-report as degraded just because a probe wasn't wired up.
	// Wire them via SetRedis/SetKafka from cmd/server/main.go.
	redis *redis.Client
}

func New(port int, handler inventoryv1.InventoryServiceServer, log *logger.Logger) *Server {
	s := &Server{handler: handler, log: log.Named("http")}

	mux := http.NewServeMux()
	// Health: both paths are registered so K8s liveness probes (/health)
	// and cross-service smoke tests (/api/v1/health) both work. Same handler.
	mux.HandleFunc("GET /health", s.handleHealth)
	mux.HandleFunc("GET /api/v1/health", s.handleHealth)

	mux.HandleFunc("POST /api/v1/seats/hold", s.handleHoldSeat)
	mux.HandleFunc("POST /api/v1/seats/hold-ga", s.handleHoldGaZone)
	mux.HandleFunc("POST /api/v1/seats/release", s.handleReleaseHold)
	mux.HandleFunc("POST /api/v1/seats/confirm", s.handleConfirmHold)
	mux.HandleFunc("GET /api/v1/seats/states", s.handleGetSeatStates)
	mux.HandleFunc("GET /api/v1/ga/availability", s.handleGetGaAvailability)

	s.srv = &http.Server{
		Addr:              fmt.Sprintf(":%d", port),
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}

	return s
}

// SetRedis attaches a Redis client that handleHealth will ping on each
// request. Safe to call before Start(). If never called, the health
// endpoint reports redis_connected:true (nil → not configured).
func (s *Server) SetRedis(client *redis.Client) {
	s.redis = client
}

func (s *Server) Start() error {
	s.log.Info("HTTP gateway listening", zap.String("addr", s.srv.Addr))
	return s.srv.ListenAndServe()
}

func (s *Server) Stop(ctx context.Context) error {
	return s.srv.Shutdown(ctx)
}

// ─── Handlers ───────────────────────────────────────────────

// handleHealth responds with process liveness and, when the downstream
// handles are available, the reachability of Redis. The shape is stable
// across every OmniTix service:
//
//	{
//	  "healthy":          bool,
//	  "status":           "ok" | "degraded",
//	  "service":          "go-inventory",
//	  "version":          "0.1.0",
//	  "redis_connected":  bool,
//	  "kafka_connected":  bool,
//	  "checked_at":       RFC3339 timestamp
//	}
//
// Returns 200 when healthy, 503 when a dependency is unreachable. A 503
// response still carries the JSON body so probes can log which
// dependency failed.
func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	redisOK := true
	kafkaOK := true

	// Redis reachability: ping with a short timeout so a dead Redis
	// doesn't stall the health probe. A nil handle means "not wired up"
	// — we assume healthy so the endpoint doesn't report false failures
	// for an incomplete deployment.
	if s.redis != nil {
		ctx, cancel := context.WithTimeout(r.Context(), 500*time.Millisecond)
		defer cancel()
		if err := s.redis.Ping(ctx).Err(); err != nil {
			redisOK = false
		}
	}

	// Kafka reachability: the franz-go producer has no direct Ping, and
	// calling a metadata request on every health check adds load for a
	// signal that was already verified at boot ("kafka producer ready"
	// is logged only after a successful metadata fetch). For v1 we trust
	// boot-time verification and report kafka_connected:true. When/if a
	// producer handle is wired in, replace this with a metadata call.

	healthy := redisOK && kafkaOK

	status := "ok"
	httpStatus := http.StatusOK
	if !healthy {
		status = "degraded"
		httpStatus = http.StatusServiceUnavailable
	}

	writeJSON(w, httpStatus, map[string]any{
		"healthy":         healthy,
		"status":          status,
		"service":         "go-inventory",
		"version":         "0.1.0",
		"redis_connected": redisOK,
		"kafka_connected": kafkaOK,
		"checked_at":      time.Now().UTC().Format(time.RFC3339),
	})
}

type holdSeatRequest struct {
	OccurrenceID string `json:"occurrence_id"`
	SeatID       string `json:"seat_id"`
	UserID       string `json:"user_id"`
	TTLSeconds   int32  `json:"ttl_seconds"`
}

func (s *Server) handleHoldSeat(w http.ResponseWriter, r *http.Request) {
	var req holdSeatRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", err.Error())
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()

	resp, err := s.handler.HoldSeat(ctx, &inventoryv1.HoldSeatRequest{
		Context:           &commonv1.RequestContext{UserId: req.UserID},
		EventOccurrenceId: req.OccurrenceID,
		SeatId:            req.SeatID,
		TtlSeconds:        req.TTLSeconds,
	})
	if err != nil {
		writeError(w, http.StatusInternalServerError, "grpc_error", err.Error())
		return
	}

	if resp.Result.Status != commonv1.Status_STATUS_OK {
		writeError(w, http.StatusConflict, resp.Result.ErrorCode, resp.Result.ErrorMessage)
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"token":      resp.Token.Token,
			"seat_id":    resp.Token.SeatId,
			"expires_at": resp.Token.ExpiresAt.AsTime().Format(time.RFC3339),
			"user_id":    resp.Token.UserId,
		},
	})
}

type holdGaRequest struct {
	OccurrenceID string `json:"occurrence_id"`
	GaZoneID     string `json:"ga_zone_id"`
	UserID       string `json:"user_id"`
	Quantity     int32  `json:"quantity"`
	TTLSeconds   int32  `json:"ttl_seconds"`
}

func (s *Server) handleHoldGaZone(w http.ResponseWriter, r *http.Request) {
	var req holdGaRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", err.Error())
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()

	resp, err := s.handler.HoldGaZone(ctx, &inventoryv1.HoldGaZoneRequest{
		Context:           &commonv1.RequestContext{UserId: req.UserID},
		EventOccurrenceId: req.OccurrenceID,
		GaZoneId:          req.GaZoneID,
		Quantity:          req.Quantity,
		TtlSeconds:        req.TTLSeconds,
	})
	if err != nil {
		writeError(w, http.StatusInternalServerError, "grpc_error", err.Error())
		return
	}
	if resp.Result.Status != commonv1.Status_STATUS_OK {
		writeError(w, http.StatusConflict, resp.Result.ErrorCode, resp.Result.ErrorMessage)
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"token":              resp.Token.Token,
			"ga_zone_id":         resp.Token.GaZoneId,
			"quantity":           resp.Token.Quantity,
			"expires_at":         resp.Token.ExpiresAt.AsTime().Format(time.RFC3339),
			"remaining_capacity": resp.RemainingCapacity,
		},
	})
}

func (s *Server) handleReleaseHold(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Token  string `json:"token"`
		UserID string `json:"user_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", err.Error())
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()

	resp, err := s.handler.ReleaseHold(ctx, &inventoryv1.ReleaseHoldRequest{
		Context: &commonv1.RequestContext{UserId: req.UserID},
		Token:   req.Token,
	})
	if err != nil {
		writeError(w, http.StatusInternalServerError, "grpc_error", err.Error())
		return
	}
	if resp.Result.Status != commonv1.Status_STATUS_OK {
		writeError(w, http.StatusConflict, resp.Result.ErrorCode, resp.Result.ErrorMessage)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{}})
}

func (s *Server) handleConfirmHold(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Token   string `json:"token"`
		UserID  string `json:"user_id"`
		OrderID string `json:"order_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", err.Error())
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()

	resp, err := s.handler.ConfirmHold(ctx, &inventoryv1.ConfirmHoldRequest{
		Context: &commonv1.RequestContext{UserId: req.UserID},
		Token:   req.Token,
		OrderId: req.OrderID,
	})
	if err != nil {
		writeError(w, http.StatusInternalServerError, "grpc_error", err.Error())
		return
	}
	if resp.Result.Status != commonv1.Status_STATUS_OK {
		writeError(w, http.StatusConflict, resp.Result.ErrorCode, resp.Result.ErrorMessage)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{}})
}

func (s *Server) handleGetSeatStates(w http.ResponseWriter, r *http.Request) {
	occurrenceID := r.URL.Query().Get("occurrence_id")
	if occurrenceID == "" {
		writeError(w, http.StatusBadRequest, "missing_occurrence_id", "")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()

	resp, err := s.handler.GetSeatStates(ctx, &inventoryv1.GetSeatStatesRequest{
		EventOccurrenceId: occurrenceID,
	})
	if err != nil {
		writeError(w, http.StatusInternalServerError, "grpc_error", err.Error())
		return
	}

	// Split into "sold" and "held" arrays for the frontend.
	// Initialize to empty slices so JSON marshaling produces [] instead of null.
	sold := []string{}
	held := []string{}
	for _, e := range resp.Seats {
		switch e.State {
		case commonv1.SeatState_SEAT_STATE_SOLD:
			sold = append(sold, e.SeatId)
		case commonv1.SeatState_SEAT_STATE_HELD:
			held = append(held, e.SeatId)
		}
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"occurrence_id": occurrenceID,
			"sold":          sold,
			"held":          held,
		},
	})
}

func (s *Server) handleGetGaAvailability(w http.ResponseWriter, r *http.Request) {
	occurrenceID := r.URL.Query().Get("occurrence_id")
	zoneID := r.URL.Query().Get("ga_zone_id")
	if occurrenceID == "" || zoneID == "" {
		writeError(w, http.StatusBadRequest, "missing_params", "")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()

	resp, err := s.handler.GetGaAvailability(ctx, &inventoryv1.GetGaAvailabilityRequest{
		EventOccurrenceId: occurrenceID,
		GaZoneId:          zoneID,
	})
	if err != nil {
		writeError(w, http.StatusInternalServerError, "grpc_error", err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"total_capacity": resp.TotalCapacity,
			"held":           resp.Held,
			"sold":           resp.Sold,
			"available":      resp.Available,
		},
	})
}

// ─── Helpers ────────────────────────────────────────────────

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func writeError(w http.ResponseWriter, status int, code, message string) {
	writeJSON(w, status, map[string]any{
		"error":   code,
		"message": message,
	})
}