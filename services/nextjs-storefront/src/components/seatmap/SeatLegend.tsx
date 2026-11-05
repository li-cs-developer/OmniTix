'use client';

import { SEAT_COLORS } from './types';

interface SeatLegendProps {
  viewerCount: number;
  connected: boolean;
}

export function SeatLegend({ viewerCount, connected }: SeatLegendProps) {
  const items: Array<[keyof typeof SEAT_COLORS, string]> = [
    ['available', 'Available'],
    ['held', 'Held'],
    ['sold', 'Sold'],
    ['selected', 'Your selection'],
  ];

  return (
    <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-950">
      <div className="flex flex-wrap gap-4">
        {items.map(([state, label]) => (
          <div key={state} className="flex items-center gap-2 text-sm">
            <span
              className="inline-block h-3 w-3 rounded-full"
              style={{ backgroundColor: SEAT_COLORS[state] }}
            />
            <span className="text-slate-600 dark:text-slate-400">{label}</span>
          </div>
        ))}
      </div>

      <div className="flex items-center gap-3 text-xs">
        <span className="flex items-center gap-1.5">
          <span
            className={`inline-block h-2 w-2 rounded-full ${
              connected ? 'bg-green-500' : 'bg-red-500'
            }`}
          />
          <span className="text-slate-600 dark:text-slate-400">
            {connected ? 'Live' : 'Reconnecting'}
          </span>
        </span>
        <span className="text-slate-500">
          {viewerCount} {viewerCount === 1 ? 'viewer' : 'viewers'}
        </span>
      </div>
    </div>
  );
}