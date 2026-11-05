'use client';

import { useMemo, useState } from 'react';
import { useStore } from '@/lib/store';
import { api, ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { CountdownTimer } from './CountdownTimer';
import { Button } from '@/components/ui/Button';
import { TicketQRCode } from './TicketQRCode';

interface CartSidebarProps {
  eventId: string;
  occurrenceId: string;
  userId: string;
}

interface ReceiptTicket {
  id: string;
  ticketNumber: string;
  seatId: string;
  qrPayload: string | null;
  qrSignature: string | null;
  signerKeyId: string | null;
  status: string;
}

type PromoState =
  | { kind: 'idle' }
  | { kind: 'checking' }
  | { kind: 'ok'; discountCents: number; code: string }
  | { kind: 'error'; message: string };

function ulid(): string {
  const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  const ts = Date.now();
  let out = '';
  let v = ts;
  for (let i = 0; i < 10; i++) {
    out = alphabet[v & 31] + out;
    v = Math.floor(v / 32);
  }
  let rand = '';
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  for (const b of bytes) rand += alphabet[b & 31];
  return (out + rand).slice(0, 26);
}

export function CartSidebar({ eventId, occurrenceId, userId }: CartSidebarProps) {
  const cart = useStore((s) => s.cart);
  const removeFromCart = useStore((s) => s.removeFromCart);
  const clearCart = useStore((s) => s.clearCart);

  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [idempotencyKey, setIdempotencyKey] = useState(() => ulid());
  const [promoInput, setPromoInput] = useState('');
  const [promoState, setPromoState] = useState<PromoState>({ kind: 'idle' });
  const [receipt, setReceipt] = useState<{
    reference: string;
    tickets: ReceiptTicket[];
  } | null>(null);

  // Reset the idempotency key whenever the cart set changes, so a new cart
  // gets a new key (retrying the same cart reuses the same key).
  const cartSignature = useMemo(
    () => cart.map((i) => i.seatId).sort().join(','),
    [cart],
  );
  useMemo(() => {
    setIdempotencyKey(ulid());
  }, [cartSignature]);

  if (cart.length === 0 && !receipt) {
    return (
      <aside className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700">
        Select seats from the map to start your order.
      </aside>
    );
  }

  if (receipt) {
    return (
      <aside className="space-y-4 rounded-lg border border-green-200 bg-green-50 p-6">
        <h3 className="text-lg font-semibold text-green-900">
          Order confirmed 🎉
        </h3>
        <p className="text-sm text-green-800">
          Reference: <span className="font-mono">{receipt.reference}</span>
        </p>
        <div className="space-y-4">
          <p className="text-sm font-medium text-green-900">Your tickets:</p>
          {receipt.tickets.map((t) => (
            <div
              key={t.id}
              className="rounded border border-green-200 bg-white p-3"
            >
              <p className="font-mono text-xs text-slate-500 mb-2">
                {t.ticketNumber}
              </p>
              {t.qrPayload && t.qrSignature && t.signerKeyId ? (
                <TicketQRCode
                  qrPayload={t.qrPayload}
                  qrSignature={t.qrSignature}
                  signerKeyId={t.signerKeyId}
                  ticketNumber={t.ticketNumber}
                  size={160}
                />
              ) : (
                <p className="text-xs text-amber-600">
                  QR generation pending…
                </p>
              )}
            </div>
          ))}
        </div>
        <Button
          variant="secondary"
          onClick={() => {
            setReceipt(null);
            setEmail('');
            setPromoInput('');
            setPromoState({ kind: 'idle' });
          }}
        >
          Done
        </Button>
      </aside>
    );
  }

  const subtotal = cart.reduce((sum, item) => sum + item.priceCents, 0);
  const earliestExpiry = cart
    .map((i) => i.expiresAt)
    .sort((a, b) => new Date(a).getTime() - new Date(b).getTime())[0];

  const discountCents =
    promoState.kind === 'ok' ? promoState.discountCents : 0;
  const payableCents = subtotal - discountCents;

  const applyPromo = async () => {
    if (!promoInput) return;
    setPromoState({ kind: 'checking' });
    try {
      const res = await fetch('/api/laravel/v1/promos/validate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          code: promoInput,
          event_id: eventId,
          user_id: userId,
          items: cart.map((item) => ({
            seat_id: item.seatId,
            price_tier_id: item.priceTierId,
            price_cents: item.priceCents,
            section_code: item.section,
          })),
        }),
      });
      const json = await res.json();
      if (json.data?.valid) {
        setPromoState({
          kind: 'ok',
          discountCents: json.data.discount_cents,
          code: json.data.code,
        });
      } else {
        setPromoState({
          kind: 'error',
          message: json.data?.reason ?? 'invalid',
        });
      }
    } catch {
      setPromoState({ kind: 'error', message: 'network' });
    }
  };

  const handleCheckout = async () => {
    if (!email || !email.includes('@')) {
      setError('Please enter a valid email address.');
      return;
    }

    setBusy(true);
    setError(null);

    try {
      const result = await api.checkout({
        occurrenceId,
        userId,
        buyerEmail: email,
        idempotencyKey,
        promoCode: promoState.kind === 'ok' ? promoState.code : undefined,
        items: cart.map((item) => ({
          seatId: item.seatId,
          holdToken: item.holdToken,
          priceTierId: item.priceTierId,
          priceCents: item.priceCents,
        })),
      });

      setReceipt({
        reference: result.reference,
        tickets: result.tickets,
      });
      clearCart();
      setIdempotencyKey(ulid()); // fresh key for next order
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : 'Checkout failed. Please try again.',
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <aside className="space-y-4 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
      <div className="flex items-baseline justify-between">
        <h3 className="text-lg font-semibold">Your seats</h3>
        <span className="text-sm text-slate-500">
          {cart.length} {cart.length === 1 ? 'seat' : 'seats'}
        </span>
      </div>

      <ul className="divide-y divide-slate-100">
        {cart.map((item) => (
          <li key={item.seatId} className="flex items-center justify-between py-2">
            <div>
              <p className="text-sm font-medium">{item.seatLabel}</p>
              <p className="text-xs text-slate-500">
                {formatMoney(item.priceCents)}
              </p>
            </div>
            <button
              onClick={() => removeFromCart(item.seatId)}
              className="text-xs text-red-600 underline hover:text-red-800"
              aria-label={`Remove ${item.seatLabel}`}
            >
              Remove
            </button>
          </li>
        ))}
      </ul>

      <div className="flex justify-between border-t border-slate-200 pt-3 text-sm font-semibold">
        <span>Subtotal</span>
        <span>{formatMoney(subtotal)}</span>
      </div>

      {discountCents > 0 && (
        <div className="flex justify-between text-sm font-semibold text-green-600">
          <span>Discount</span>
          <span>−{formatMoney(discountCents)}</span>
        </div>
      )}

      <div className="text-xs text-slate-500">
        Holds expire in <CountdownTimer expiresAt={earliestExpiry} />
      </div>

      {/* Promo code */}
      <div className="space-y-2">
        <label className="block text-xs uppercase tracking-wide text-slate-500">
          Promo code
        </label>
        <div className="flex gap-2">
          <input
            value={promoInput}
            onChange={(e) => setPromoInput(e.target.value.toUpperCase())}
            placeholder="OPTIONAL"
            className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
            disabled={busy}
          />
          <button
            onClick={applyPromo}
            className="rounded-md bg-slate-800 px-4 text-sm text-white"
            disabled={busy || !promoInput}
          >
            Apply
          </button>
        </div>
        {promoState.kind === 'ok' && (
          <p className="text-xs text-green-600">
            {promoState.code} applied — saving {formatMoney(promoState.discountCents)}
          </p>
        )}
        {promoState.kind === 'error' && (
          <p className="text-xs text-red-600">{promoState.message}</p>
        )}
      </div>

      <div className="space-y-2">
        <label className="block text-xs uppercase tracking-wide text-slate-500">
          Email for delivery
        </label>
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="you@example.com"
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900"
          disabled={busy}
        />
      </div>

      {error && (
        <p className="text-xs text-red-600" role="alert">
          {error}
        </p>
      )}

      <Button
        onClick={handleCheckout}
        disabled={busy}
        size="lg"
        className="w-full"
      >
        {busy ? 'Processing…' : `Pay ${formatMoney(payableCents)}`}
      </Button>
    </aside>
  );
}