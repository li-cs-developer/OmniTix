'use client';

import { useState } from 'react';
import Link from 'next/link';
import { formatMoney } from '@/lib/format';

interface OrderSummary {
  order_id: string;
  reference: string;
  total_cents: number;
  currency: string;
  status: string;
  placed_at: string;
}

export default function LookupPage() {
  const [email, setEmail] = useState('');
  const [orders, setOrders] = useState<OrderSummary[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleLookup() {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch('/api/laravel/v1/account/lookup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email }),
      });
      const json = await res.json();
      setOrders(json.data ?? []);
    } catch (err: any) {
      setError(err.message ?? 'Lookup failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="max-w-xl mx-auto space-y-6">
      <h1 className="text-3xl font-bold">Find your tickets</h1>
      <div className="flex gap-2">
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="you@example.com"
          className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
        <button
          onClick={handleLookup}
          disabled={busy || !email.includes('@')}
          className="rounded-md bg-brand-600 px-4 text-sm font-medium text-white disabled:opacity-50"
        >
          {busy ? '…' : 'Find'}
        </button>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}
      {orders && orders.length === 0 && (
        <p className="text-sm text-slate-500">No orders found for that email.</p>
      )}
      {orders && orders.length > 0 && (
        <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200">
          {orders.map((o) => (
            <li key={o.order_id}>
              <Link
                href={`/account/orders/${o.reference}?email=${encodeURIComponent(email)}`}
                className="flex items-center justify-between p-4 hover:bg-slate-50"
              >
                <div>
                  <p className="font-mono text-sm">{o.reference}</p>
                  <p className="text-xs text-slate-500">
                    {new Date(o.placed_at).toLocaleDateString()} — {o.status}
                  </p>
                </div>
                <span className="font-medium">{formatMoney(o.total_cents, o.currency)}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}