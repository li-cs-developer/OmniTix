import { notFound } from 'next/navigation';
import { TicketQRCode } from '@/app/events/[slug]/TicketQRCode';
import { formatMoney, formatDateTime } from '@/lib/format';

export const dynamic = 'force-dynamic';

interface PageProps {
  params: { reference: string };
  searchParams: { email?: string };
}

export default async function OrderPage({ params, searchParams }: PageProps) {
  if (!searchParams.email) notFound();

  const url = `${process.env.LARAVEL_INTERNAL_URL ?? 'http://localhost:8000'}/api/v1/account/orders/${encodeURIComponent(
    params.reference,
  )}?email=${encodeURIComponent(searchParams.email)}`;

  const res = await fetch(url, { cache: 'no-store' });
  if (!res.ok) notFound();

  const { data: order } = await res.json();

  return (
    <div className="max-w-2xl mx-auto space-y-6">
      <div>
        <p className="text-xs uppercase tracking-wide text-slate-500">Order</p>
        <h1 className="text-2xl font-bold font-mono">{order.reference}</h1>
        <p className="text-sm text-slate-500">
          {order.event?.title} — {formatMoney(order.total_cents, order.currency)}
        </p>
        {order.occurrence && (
          <p className="text-sm text-slate-600">
            {formatDateTime(order.occurrence.starts_at, 'America/Chicago')}
            {order.event?.venue_name && ` · ${order.event.venue_name}`}
          </p>
        )}
      </div>

      <div className="space-y-4">
        <h2 className="text-lg font-semibold">Your tickets</h2>
        {order.tickets.map((t: any) => (
          <div
            key={t.id}
            className="rounded-lg border border-slate-200 bg-white p-4 flex gap-4"
          >
            <div className="flex-1">
              <p className="font-mono text-xs text-slate-500">{t.ticket_number}</p>
              <p className="font-medium">{t.seat_label ?? t.tier_name}</p>
              <p className="text-sm text-slate-500">{t.status}</p>
            </div>
            {t.qr_payload && t.qr_signature && t.signer_key_id && t.status !== 'voided' && (
              <TicketQRCode
                qrPayload={t.qr_payload}
                qrSignature={t.qr_signature}
                signerKeyId={t.signer_key_id}
                ticketNumber={t.ticket_number}
                size={140}
              />
            )}
          </div>
        ))}
      </div>
    </div>
  );
}