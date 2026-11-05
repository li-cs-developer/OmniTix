'use client';

import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { SEAT_COLORS, type SeatLayoutEntry } from './types';
import type { SeatState } from '@/lib/ws';

interface SeatTooltipProps {
  seat: SeatLayoutEntry | null;
  state: SeatState;
  priceCents: number | null;
  event: MouseEvent | null;
}

export function SeatTooltip({ seat, state, priceCents, event }: SeatTooltipProps) {
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  if (!mounted || !seat || !event) return null;

  const price = priceCents
    ? `$${(priceCents / 100).toFixed(2)}`
    : '';

  const stateLabel = {
    available: 'Available',
    held: 'On hold',
    sold: 'Sold',
    blocked: 'Blocked',
    unavailable: 'Unavailable',
  }[state];

  // Keep tooltip within viewport bounds.
  const OFFSET = 12;
  const TOOLTIP_WIDTH = 200;
  const left = Math.min(
    event.clientX + OFFSET,
    window.innerWidth - TOOLTIP_WIDTH - 8,
  );
  const top = event.clientY + OFFSET;

  return createPortal(
    <div
      className="pointer-events-none fixed z-50 rounded-md bg-slate-900 px-3 py-2 text-xs text-white shadow-lg"
      style={{ left, top }}
    >
      <div className="font-semibold">{seat.label}</div>
      <div className="mt-1 flex items-center gap-1.5">
        <span
          className="inline-block h-2 w-2 rounded-full"
          style={{ backgroundColor: SEAT_COLORS[state] }}
        />
        <span className="text-slate-300">{stateLabel}</span>
        {price && <span className="ml-auto font-medium">{price}</span>}
      </div>
    </div>,
    document.body,
  );
}