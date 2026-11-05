import { Injectable, Logger } from '@nestjs/common';
import { WsGateway } from '../websocket/ws.gateway';
import { RoomService } from '../websocket/room.service';

@Injectable()
export class EventHandlerService {
  private readonly logger = new Logger(EventHandlerService.name);

  constructor(
    private readonly ws: WsGateway,
    private readonly rooms: RoomService,
  ) {}

  async handle(topic: string, envelope: any, partition: number): Promise<void> {
    const type = envelope.type;
    const payload = envelope.payload;
    const occurrenceId = payload?.event_occurrence_id;

    if (!occurrenceId) {
      this.logger.warn(`event ${type} has no event_occurrence_id`);
      return;
    }

    switch (type) {
      case 'seat.held':
        this.broadcastSeatUpdate(
          occurrenceId,
          payload.seat_id,
          'held',
          payload.user_id,
        );
        break;

      case 'seat.expired':
        this.broadcastSeatUpdate(
          occurrenceId,
          payload.seat_id,
          'available',
          payload.user_id,
        );
        break;

      case 'seat.sold':
        this.broadcastSeatUpdate(
          occurrenceId,
          payload.seat_id,
          'sold',
          payload.user_id,
        );
        break;

      case 'ga.held':
      case 'ga.expired':
      case 'ga.sold':
        this.broadcastGaUpdate(occurrenceId, payload);
        break;

      case 'ticket.issued':
        this.ws.broadcastToRoom(occurrenceId, {
          type: 'ticket.issued',
          event_occurrence_id: occurrenceId,
          ticket_id: payload.ticket_id,
          ticket_number: payload.ticket_number,
          seat_id: payload.seat_id ?? null,
          issued_at: payload.issued_at,
        });
        break;

      case 'ticket.scanned':
        this.ws.broadcastToRoom(occurrenceId, {
          type: 'ticket.scanned',
          event_occurrence_id: occurrenceId,
          ticket_id: payload.ticket_id,
          ticket_number: payload.ticket_number,
          scan_result: payload.scan_result,
          gate_identifier: payload.gate_identifier,
          scanned_at: payload.scanned_at,
        });
        break;

      default:
        this.logger.debug(`unhandled event type: ${type}`);
    }
  }

  private broadcastSeatUpdate(
    occurrenceId: string,
    seatId: string,
    state: 'available' | 'held' | 'sold',
    heldByUserId?: string,
  ): void {
    this.logger.debug(
      `broadcast: seat ${seatId} → ${state} (occurrence ${occurrenceId}, user ${heldByUserId ?? 'unknown'})`,
    );
    this.ws.broadcastToRoom(occurrenceId, {
      type: 'seat.update',
      event_occurrence_id: occurrenceId,
      seat_id: seatId,
      state,
      held_by_user_id: heldByUserId,
      updated_at: new Date().toISOString(),
    });
  }

  private broadcastGaUpdate(occurrenceId: string, payload: any): void {
    this.ws.broadcastToRoom(occurrenceId, {
      type: 'ga.update',
      event_occurrence_id: occurrenceId,
      ga_zone_id: payload.ga_zone_id,
      held: payload.quantity ?? 0,
      sold: 0,
      available: payload.remaining_capacity ?? 0,
      updated_at: new Date().toISOString(),
    });
  }
}