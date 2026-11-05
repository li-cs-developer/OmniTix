'use client';

import { io, type Socket } from 'socket.io-client';
import { publicEnv } from './env';

export type SeatState = 'available' | 'held' | 'sold' | 'blocked' | 'unavailable';

export type ServerBatch = {
  type: 'batch';
  messages: ServerMessage[];
};

export type ServerMessage =
  | {
      type: 'snapshot';
      event_occurrence_id: string;
      seats: Array<{ seat_id: string; state: SeatState }>;
      viewer_count: number;
      snapshot_at: string;
    }
  | {
      type: 'seat.update';
      event_occurrence_id: string;
      seat_id: string;
      state: SeatState;
      held_by_user_id?: string;
      updated_at: string;
    }
  | {
      type: 'ga.update';
      event_occurrence_id: string;
      ga_zone_id: string;
      held: number;
      sold: number;
      available: number;
      updated_at: string;
    }
  | {
      type: 'viewer.count';
      event_occurrence_id: string;
      viewer_count: number;
    }
  | {
      type: 'pong';
      server_sent_at: string;
      client_sent_at?: string;
    }
  | {
      type: 'error';
      code: string;
      message: string;
    }
  | {
      type: 'ticket.issued';
      event_occurrence_id: string;
      ticket_id: string;
      ticket_number: string;
      seat_id: string | null;
      issued_at: string;
    }
  | {
      type: 'ticket.scanned';
      event_occurrence_id: string;
      ticket_id: string;
      ticket_number: string;
      scan_result: string;
      gate_identifier: string;
      scanned_at: string;
    }
  | ServerBatch;

export type ClientMessage =
  | { type: 'subscribe'; event_occurrence_id: string }
  | { type: 'unsubscribe'; event_occurrence_id: string }
  | { type: 'ping'; sent_at?: string };

export interface WsHandlers {
  onMessage: (msg: ServerMessage) => void;
  onConnect?: () => void;
  onDisconnect?: (reason: string) => void;
  onError?: (err: Error) => void;
}

/**
 * Connect to the OmniTix WebSocket gateway.
 *
 * Returns a client object with `subscribe`, `unsubscribe`, `ping`, and `close`.
 * The caller owns the returned object and must call `close()` on cleanup.
 */
export function connectWs(handlers: WsHandlers) {
  const socket: Socket = io(publicEnv.NEXT_PUBLIC_WS_URL, {
    path: publicEnv.NEXT_PUBLIC_WS_PATH,
    transports: ['websocket'],
    reconnection: true,
    reconnectionDelay: 1000,
    reconnectionDelayMax: 10000,
    reconnectionAttempts: Infinity,
  });

  socket.on('connect', () => {
    handlers.onConnect?.();
  });

  socket.on('disconnect', (reason) => {
    handlers.onDisconnect?.(reason);
  });

  socket.on('connect_error', (err) => {
    handlers.onError?.(err);
  });

  socket.on('message', (msg: ServerMessage) => {
    handlers.onMessage(msg);
  });

  return {
    subscribe(occurrenceId: string) {
      const msg: ClientMessage = {
        type: 'subscribe',
        event_occurrence_id: occurrenceId,
      };
      socket.emit('message', msg);
    },

    unsubscribe(occurrenceId: string) {
      const msg: ClientMessage = {
        type: 'unsubscribe',
        event_occurrence_id: occurrenceId,
      };
      socket.emit('message', msg);
    },

    ping() {
      const msg: ClientMessage = {
        type: 'ping',
        sent_at: new Date().toISOString(),
      };
      socket.emit('message', msg);
    },

    isConnected(): boolean {
      return socket.connected;
    },

    close() {
      socket.removeAllListeners();
      socket.close();
    },
  };
}

export type WsClient = ReturnType<typeof connectWs>;