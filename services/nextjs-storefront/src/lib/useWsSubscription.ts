'use client';

import { useEffect, useRef } from 'react';
import { connectWs, type WsClient, type ServerMessage } from './ws';
import { useStore } from './store';

/**
 * Connect to the WebSocket gateway and subscribe to an event occurrence.
 *
 * On every snapshot, seats that are in this client's cart are preserved
 * as their current state (usually "held by me"), even if the server
 * snapshot says they're held by someone else.
 */
export function useWsSubscription(occurrenceId: string | null | undefined) {
  const clientRef = useRef<WsClient | null>(null);

  const setSnapshot = useStore((s) => s.setSnapshot);
  const applySeatUpdate = useStore((s) => s.applySeatUpdate);
  const setViewerCount = useStore((s) => s.setViewerCount);
  const setConnected = useStore((s) => s.setConnected);

  useEffect(() => {
    if (!occurrenceId) return;

    // Batched messages arrive as { type: "batch", messages: [...] }.
    // Unpack recursively before handling.
    function handleOne(msg: ServerMessage): void {
      if (msg.type === 'batch') {
        for (const m of msg.messages) handleOne(m);
        return;
      }

      switch (msg.type) {
        case 'snapshot': {
          // The server snapshot marks seats as held/sold based on Redis
          // alone — it doesn't know which holds belong to *this* client.
          // Override: for any seat in our cart, treat the seat as ours.
          const cartSeatIds = new Set(
            useStore.getState().cart.map((item) => item.seatId),
          );
          const filteredSeats = msg.seats.filter(
            (s) => !cartSeatIds.has(s.seat_id),
          );
          setSnapshot(
            msg.event_occurrence_id,
            filteredSeats,
            msg.viewer_count,
          );
          break;
        }

        case 'seat.update': {
          const { cart } = useStore.getState();
          const cartItem = cart.find((i) => i.seatId === msg.seat_id);

          // Preserve our own seat state if:
          //   a) the seat is in our cart, AND
          //   b) either the server didn't specify an owner, OR
          //      the server says WE are the owner.
          //
          // This prevents:
          //   - Our own hold from being marked yellow by a Kafka echo
          //   - Our own release from being ignored (available event has
          //     our user_id, so we fall through to apply the change)
          if (
            cartItem &&
            (msg.held_by_user_id === undefined ||
              msg.held_by_user_id === cartItem.userId)
          ) {
            // It's our seat. If the event says available, apply it (we
            // released it elsewhere). Otherwise keep our local state.
            if (msg.state === 'available') {
              applySeatUpdate(msg.seat_id, 'available');
            }
            break;
          }

          applySeatUpdate(msg.seat_id, msg.state);
          break;
        }

        case 'viewer.count':
          setViewerCount(msg.viewer_count);
          break;

        case 'ticket.issued':
        case 'ticket.scanned':
          // Optional: surface in a "ticker" or scanner UI.
          break;

        case 'error':
          console.warn('[ws] server error:', msg.code, msg.message);
          break;

        case 'pong':
        case 'ga.update':
          break;
      }
    }

    const client = connectWs({
      onConnect() {
        setConnected(true);
        client.subscribe(occurrenceId);
      },

      onDisconnect() {
        setConnected(false);
      },

      onError(err) {
        console.error('[ws] error:', err);
      },

      onMessage(msg: ServerMessage) {
        handleOne(msg);
      },
    });

    clientRef.current = client;

    return () => {
      if (occurrenceId) {
        client.unsubscribe(occurrenceId);
      }
      client.close();
      clientRef.current = null;
      setConnected(false);
    };
  }, [occurrenceId, setSnapshot, applySeatUpdate, setViewerCount, setConnected]);

  return clientRef;
}