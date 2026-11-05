// Package grpc implements the OmniTix InventoryService gRPC server.
package grpc

import (
	"time"

	commonv1 "github.com/omnitix/go-inventory/gen/go/omnitix/common/v1"
	"github.com/omnitix/go-inventory/internal/inventory"
	"google.golang.org/protobuf/types/known/timestamppb"
)

// ─── Result envelope ────────────────────────────────────────

func okResult() *commonv1.Result {
	return &commonv1.Result{Status: commonv1.Status_STATUS_OK}
}

func errResult(code, message string) *commonv1.Result {
	status := commonv1.Status_STATUS_ERROR
	switch code {
	case inventory.ErrCodeInvalidRequest:
		status = commonv1.Status_STATUS_INVALID_ARGUMENT
	case inventory.ErrCodeSeatAlreadyHeld, inventory.ErrCodeGaInsufficient:
		status = commonv1.Status_STATUS_CONFLICT
	case inventory.ErrCodeSeatAlreadySold:
		status = commonv1.Status_STATUS_CONFLICT
	case inventory.ErrCodeHoldNotFound:
		status = commonv1.Status_STATUS_NOT_FOUND
	case inventory.ErrCodeHoldNotOwner:
		status = commonv1.Status_STATUS_PERMISSION_DENIED
	case inventory.ErrCodeGaCapacityMissing:
		status = commonv1.Status_STATUS_UNAVAILABLE
	case inventory.ErrCodeInternal:
		status = commonv1.Status_STATUS_ERROR
	}
	return &commonv1.Result{
		Status:       status,
		ErrorCode:    code,
		ErrorMessage: message,
	}
}

// errResultFromError extracts code/message from an inventory.Error, falling
// back to a generic INTERNAL_ERROR if the error isn't ours.
func errResultFromError(err error) *commonv1.Result {
	if err == nil {
		return okResult()
	}
	if ie, ok := err.(*inventory.Error); ok {
		return errResult(ie.Code, ie.Message)
	}
	return errResult(inventory.ErrCodeInternal, err.Error())
}

// ─── HoldToken ──────────────────────────────────────────────

func holdToToken(h *inventory.Hold) *commonv1.HoldToken {
	if h == nil {
		return nil
	}
	return &commonv1.HoldToken{
		Token:             h.Token,
		SeatId:            h.SeatID,
		GaZoneId:          h.GaZoneID,
		Quantity:          int32(h.Quantity),
		ExpiresAt:         timestamppb.New(h.ExpiresAt),
		EventOccurrenceId: h.OccurrenceID,
		UserId:            h.UserID,
	}
}

// ─── SeatState ──────────────────────────────────────────────

func seatStateToProto(s inventory.SeatState) commonv1.SeatState {
	switch s {
	case inventory.SeatStateAvailable:
		return commonv1.SeatState_SEAT_STATE_AVAILABLE
	case inventory.SeatStateHeld:
		return commonv1.SeatState_SEAT_STATE_HELD
	case inventory.SeatStateSold:
		return commonv1.SeatState_SEAT_STATE_SOLD
	case inventory.SeatStateBlocked:
		return commonv1.SeatState_SEAT_STATE_BLOCKED
	default:
		return commonv1.SeatState_SEAT_STATE_UNAVAILABLE
	}
}

// ─── RequestContext passthrough (for logging) ──────────────

// contextFromProto pulls common fields out of the RequestContext for logging.
// Returns zero values if the context is nil.
func contextFromProto(c *commonv1.RequestContext) (requestID, userID, organizerID, ip string) {
	if c == nil {
		return "", "", "", ""
	}
	return c.RequestId, c.UserId, c.OrganizerId, c.IpAddress
}

// ─── Time helpers ───────────────────────────────────────────

// durationToTTL converts a proto TTL hint (seconds) to a Go duration.
// 0 or negative returns 0, signalling "use service default".
func durationToTTL(seconds int32) time.Duration {
	if seconds <= 0 {
		return 0
	}
	return time.Duration(seconds) * time.Second
}