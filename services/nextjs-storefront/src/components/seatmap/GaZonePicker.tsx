'use client';

import { useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { useStore } from '@/lib/store';
import { formatMoney } from '@/lib/format';
import { Button } from '@/components/ui/Button';
import type { GaZoneEntry } from './types';

interface GaZonePickerProps {
  occurrenceId: string;
  userId: string;
  zones: GaZoneEntry[];
  /**
   * Serialized map of zone id → pricing. Passed from the Server Component
   * as a plain object because React Server Components cannot serialize
   * function props (a `priceForZone(zone)` closure would trigger:
   *   "Functions cannot be passed directly to Client Components ...").
   */
  zonePricing: Record<string, { tierId: string; priceCents: number } | null>;
  onAdded?: () => void;
}

export function GaZonePicker({
  occurrenceId,
  userId,
  zones,
  zonePricing,
  onAdded,
}: GaZonePickerProps) {
  const addToCart = useStore((s) => s.addToCart);
  const [zoneId, setZoneId] = useState<string>(zones[0]?.id ?? '');
  const [quantity, setQuantity] = useState(2);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const zone = zones.find((z) => z.id === zoneId);
  const pricing = zone ? (zonePricing[zone.id] ?? null) : null;

  async function handleAdd() {
    if (!zone || !pricing) return;
    setBusy(true);
    setError(null);
    try {
      const hold = await api.holdGaZone({
        occurrenceId,
        gaZoneId: zone.id,
        quantity,
        userId,
      });
      addToCart({
        seatId: `ga-${zone.id}-${hold.token.slice(-6)}`, // synthetic stable id
        gaZoneId: zone.id,
        seatLabel: `${zone.name} × ${quantity}`,
        section: zone.code,
        row: '',
        number: '',
        quantity,
        priceTierId: pricing.tierId,
        priceCents: pricing.priceCents * quantity,
        holdToken: hold.token,
        expiresAt: hold.expires_at,
        userId,
      });
      onAdded?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not hold GA seats.');
    } finally {
      setBusy(false);
    }
  }

  if (zones.length === 0) return null;

  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <h3 className="font-semibold mb-2">General Admission</h3>
      <div className="flex gap-2">
        <select
          value={zoneId}
          onChange={(e) => setZoneId(e.target.value)}
          className="flex-1 rounded border border-slate-300 px-2 py-1 text-sm"
        >
          {zones.map((z) => (
            <option key={z.id} value={z.id}>
              {z.name} (capacity {z.capacity})
            </option>
          ))}
        </select>
        <input
          type="number"
          min={1}
          max={20}
          value={quantity}
          onChange={(e) => setQuantity(Math.max(1, parseInt(e.target.value || '1', 10)))}
          className="w-20 rounded border border-slate-300 px-2 py-1 text-sm"
        />
        <Button onClick={handleAdd} disabled={busy || !pricing}>
          {busy ? '…' : 'Add'}
        </Button>
      </div>
      {pricing && (
        <p className="text-xs text-slate-500 mt-2">
          {formatMoney(pricing.priceCents)} per ticket
        </p>
      )}
      {error && <p className="text-xs text-red-600 mt-2">{error}</p>}
    </div>
  );
}