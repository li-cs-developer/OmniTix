'use client';

import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { SeatState } from './ws';

interface CartItem {
  seatId: string;
  gaZoneId?: string;        // set for GA items
  seatLabel: string;
  section: string;
  row: string;
  number: string;
  quantity: number;         // 1 for seat, N for GA
  priceTierId: string;
  priceCents: number;
  holdToken: string;
  expiresAt: string;
  userId: string;
}

interface StoreState {
  // ─── Seat map data (populated on event detail load) ────
  seatStates: Record<string, SeatState>;
  viewerCount: number;
  connected: boolean;

  // ─── Cart (single-event) ────────────────────────────────
  occurrenceId: string | null;
  cart: CartItem[];
  holdExpiresAt: string | null;

  // ─── Actions ────────────────────────────────────────────
  setSnapshot: (
    occurrenceId: string,
    seats: Array<{ seat_id: string; state: SeatState }>,
    viewerCount: number,
  ) => void;
  applySeatUpdate: (seatId: string, state: SeatState) => void;
  setViewerCount: (n: number) => void;
  setConnected: (v: boolean) => void;

  addToCart: (item: CartItem) => void;
  removeFromCart: (seatId: string) => void;
  clearCart: () => void;
}

export const useStore = create<StoreState>()(
  persist(
    (set) => ({
      seatStates: {},
      viewerCount: 0,
      connected: false,

      occurrenceId: null,
      cart: [],
      holdExpiresAt: null,

      setSnapshot(occurrenceId, seats, viewerCount) {
        const seatStates: Record<string, SeatState> = {};
        for (const s of seats) {
          seatStates[s.seat_id] = s.state;
        }
        set({ occurrenceId, seatStates, viewerCount });
      },

      applySeatUpdate(seatId, state) {
        set((prev) => ({
          seatStates: { ...prev.seatStates, [seatId]: state },
        }));
      },

      setViewerCount(n) {
        set({ viewerCount: n });
      },

      setConnected(v) {
        set({ connected: v });
      },

      addToCart(item) {
        set((prev) => ({
          cart: [...prev.cart, item],
          holdExpiresAt: item.expiresAt,
          seatStates: { ...prev.seatStates, [item.seatId]: 'held' },
        }));
      },

      removeFromCart(seatId) {
        set((prev) => ({
          cart: prev.cart.filter((i) => i.seatId !== seatId),
          seatStates: { ...prev.seatStates, [seatId]: 'available' },
        }));
      },

      clearCart() {
        set({ cart: [], holdExpiresAt: null });
      },
    }),
    {
      name: 'omnitix-store',
      // Only persist cart-related fields. Seat states should come fresh from
      // the WS snapshot, not from stale localStorage.
      partialize: (state) => ({
        cart: state.cart,
        occurrenceId: state.occurrenceId,
        holdExpiresAt: state.holdExpiresAt,
      }),
    },
  ),
);