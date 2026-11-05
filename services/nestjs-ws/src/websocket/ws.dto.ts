/**
 * Client → Server messages.
 */
export type ClientMessage =
  | { type: 'subscribe'; event_occurrence_id: string }
  | { type: 'unsubscribe'; event_occurrence_id: string }
  | { type: 'ping'; sent_at?: string };

/**
 * Server → Client messages.
 */
export type ServerMessage =
  | ServerSnapshot
  | ServerSeatUpdate
  | ServerGaUpdate
  | ServerTicketIssued
  | ServerTicketScanned
  | ServerViewerCount
  | ServerPong
  | ServerError;

export interface SeatStateEntry {
  seat_id: string;
  state: 'available' | 'held' | 'sold' | 'blocked' | 'unavailable';
}

export interface ServerSnapshot {
  type: 'snapshot';
  event_occurrence_id: string;
  seats: SeatStateEntry[];
  viewer_count: number;
  snapshot_at: string;
}

export interface ServerSeatUpdate {
  type: 'seat.update';
  event_occurrence_id: string;
  seat_id: string;
  state: SeatStateEntry['state'];
  held_by_user_id?: string;
  updated_at: string;
}

export interface ServerGaUpdate {
  type: 'ga.update';
  event_occurrence_id: string;
  ga_zone_id: string;
  held: number;
  sold: number;
  available: number;
  updated_at: string;
}

export interface ServerTicketIssued {
  type: 'ticket.issued';
  event_occurrence_id: string;
  ticket_id: string;
  ticket_number: string;
  seat_id: string | null;
  issued_at: string;
}

export interface ServerTicketScanned {
  type: 'ticket.scanned';
  event_occurrence_id: string;
  ticket_id: string;
  ticket_number: string;
  scan_result: string;
  gate_identifier: string;
  scanned_at: string;
}

export interface ServerViewerCount {
  type: 'viewer.count';
  event_occurrence_id: string;
  viewer_count: number;
}

export interface ServerPong {
  type: 'pong';
  server_sent_at: string;
  client_sent_at?: string;
}

export interface ServerError {
  type: 'error';
  code: string;
  message: string;
}

/**
 * Room name convention for a given event occurrence.
 */
export function roomFor(occurrenceId: string): string {
  return `occurrence:${occurrenceId}`;
}