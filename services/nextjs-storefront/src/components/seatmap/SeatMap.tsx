'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { useStore } from '@/lib/store';
import { useWsSubscription } from '@/lib/useWsSubscription';
import { SvgSeatMap } from './SvgSeatMap';
import { SeatTooltip } from './SeatTooltip';
import { SeatLegend } from './SeatLegend';
import type {
  GaZoneEntry,
  SeatLayoutEntry,
  SeatMapLayout,
} from './types';

interface SeatMapProps {
  eventId: string;
  occurrenceId: string;
  /** Map section code → price tier ID + price (from event detail). */
  sectionPricing: Record<string, { tierId: string; priceCents: number }>;
  /** User identifier (guest UUID for v1). */
  userId: string;
}

export function SeatMap({
  eventId,
  occurrenceId,
  sectionPricing,
  userId,
}: SeatMapProps) {
  const [layout, setLayout] = useState<SeatMapLayout | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [hoverSeat, setHoverSeat] = useState<SeatLayoutEntry | null>(null);
  const [hoverEvent, setHoverEvent] = useState<MouseEvent | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [pending, setPending] = useState<Set<string>>(new Set());

  // From Zustand
  const seatStates = useStore((s) => s.seatStates);
  const viewerCount = useStore((s) => s.viewerCount);
  const connected = useStore((s) => s.connected);
  const cart = useStore((s) => s.cart);
  const addToCart = useStore((s) => s.addToCart);
  const removeFromCart = useStore((s) => s.removeFromCart);
  const clearCart = useStore((s) => s.clearCart);

  // Subscribe to WebSocket updates.
  useWsSubscription(occurrenceId);

  // ─── Load layout ────────────────────────────────────────
  useEffect(() => {
    let cancelled = false;
    api
      .getSeatLayout(eventId)
      .then((data) => {
        if (!cancelled) setLayout(data);
      })
      .catch((err) => {
        if (!cancelled) {
          setLoadError(
            err instanceof ApiError ? err.message : 'Failed to load seat map',
          );
        }
      });
    return () => {
      cancelled = true;
    };
  }, [eventId]);

  const selectedSeatIds = useMemo(
    () => new Set(cart.map((item) => item.seatId)),
    [cart],
  );

  // ─── Helpers ────────────────────────────────────────────
  const showToast = useCallback((msg: string) => {
    setToast(msg);
    setTimeout(() => setToast(null), 3500);
  }, []);

  // ─── Seat click → hold or release ───────────────────────
  const handleSeatClick = useCallback(
    async (seat: SeatLayoutEntry) => {
      // ─── Case 1: Already in cart → RELEASE ─────────────
      const cartItem = cart.find((i) => i.seatId === seat.id);
      if (cartItem) {
        if (pending.has(seat.id)) return;
        setPending((p) => new Set(p).add(seat.id));
        try {
          await api.releaseSeat({
            token: cartItem.holdToken,
            userId: cartItem.userId,
          });
          removeFromCart(seat.id);
          showToast(`Released ${seat.label}`);
        } catch (err) {
          showToast(
            err instanceof ApiError
              ? err.message
              : 'Could not release seat. Please try again.',
          );
        } finally {
          setPending((p) => {
            const next = new Set(p);
            next.delete(seat.id);
            return next;
          });
        }
        return;
      }

      // ─── Case 2: Held by someone else → reject ─────────
      const state = seatStates[seat.id] ?? 'available';
      if (state !== 'available') {
        showToast(`Seat ${seat.label} is ${state}.`);
        return;
      }
      if (pending.has(seat.id)) return;

      const pricing = sectionPricing[seat.section_code];
      if (!pricing) {
        showToast('No pricing configured for this section.');
        return;
      }

      setPending((p) => new Set(p).add(seat.id));

      // Optimistic UI
      useStore.setState((s) => ({
        seatStates: { ...s.seatStates, [seat.id]: 'held' },
      }));

      try {
        const result = await api.holdSeat({
          occurrenceId,
          seatId: seat.id,
          userId,
        });

        addToCart({
          seatId: seat.id,
          seatLabel: seat.label,
          section: seat.section_code,
          row: seat.row,
          number: seat.number,
          quantity: 1,
          priceTierId: pricing.tierId,
          priceCents: pricing.priceCents,
          holdToken: result.token,
          expiresAt: result.expires_at,
          userId,
        });
      } catch (err) {
        // Revert
        useStore.setState((s) => ({
          seatStates: { ...s.seatStates, [seat.id]: 'available' },
        }));
        showToast(
          err instanceof ApiError
            ? err.message
            : 'Could not hold seat. Please try again.',
        );
      } finally {
        setPending((p) => {
          const next = new Set(p);
          next.delete(seat.id);
          return next;
        });
      }
    },
    [
      cart,
      seatStates,
      pending,
      sectionPricing,
      occurrenceId,
      userId,
      addToCart,
      removeFromCart,
      showToast,
    ],
  );

  const handleGaZoneClick = useCallback(
    (zone: GaZoneEntry) => {
      showToast(
        `GA zone "${zone.name}" (capacity ${zone.capacity}) — GA holds coming in v2.`,
      );
    },
    [showToast],
  );

  // ─── Render ─────────────────────────────────────────────
  if (loadError) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-700">
        {loadError}
      </div>
    );
  }

  if (!layout) {
    return (
      <div className="rounded-lg border border-slate-200 bg-slate-50 p-12 text-center text-sm text-slate-500">
        Loading seat map…
      </div>
    );
  }

  const hoverPrice = hoverSeat
    ? sectionPricing[hoverSeat.section_code]?.priceCents ?? null
    : null;
  const hoverState = hoverSeat
    ? seatStates[hoverSeat.id] ?? 'available'
    : 'available';

  return (
    <div className="space-y-4">
      <SeatLegend viewerCount={viewerCount} connected={connected} />

      <div className="overflow-auto rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
        <SvgSeatMap
          layout={layout}
          seatStates={seatStates}
          selectedSeatIds={selectedSeatIds}
          highlightSeatId={hoverSeat?.id ?? null}
          onSeatClick={handleSeatClick}
          onSeatHover={(seat, event) => {
            setHoverSeat(seat);
            setHoverEvent(event?.nativeEvent ?? null);
          }}
          onGaZoneClick={handleGaZoneClick}
        />
      </div>

      {cart.length > 0 && (
        <div className="flex items-center justify-between rounded-lg border border-brand-200 bg-brand-50 px-4 py-3">
          <span className="text-sm font-medium text-brand-900">
            {cart.length} seat{cart.length > 1 ? 's' : ''} in your cart
          </span>
          <button
            onClick={clearCart}
            className="text-sm text-brand-700 underline hover:text-brand-900"
          >
            Clear
          </button>
        </div>
      )}

      {toast && (
        <div className="fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-md bg-slate-900 px-4 py-2 text-sm text-white shadow-lg">
          {toast}
        </div>
      )}

      <SeatTooltip
        seat={hoverSeat}
        state={hoverState}
        priceCents={hoverPrice}
        event={hoverEvent}
      />
    </div>
  );
}