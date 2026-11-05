import Link from 'next/link';
import { api } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';

// Force every request to re-fetch from Laravel.
// Without `revalidate = 0`, Next.js can bake the build-time response
// (empty, because Laravel wasn't reachable during `next build`) into the
// standalone output and serve it forever, even with `dynamic = 'force-dynamic'`.
export const dynamic = 'force-dynamic';
export const revalidate = 0;

// Server Component — fetches directly on the server, no API hop.
export default async function HomePage() {
  const events = await api.listEvents({ server: true });

  return (
    <div className="space-y-8">
      <section className="text-center">
        <h1 className="text-4xl font-bold tracking-tight">
          Find your next event
        </h1>
        <p className="mt-3 text-lg text-slate-600 dark:text-slate-400">
          Live seat maps, instant holds, cryptographic tickets.
        </p>
      </section>

      {events.length === 0 ? (
        <section className="rounded-lg border border-dashed border-slate-300 p-12 text-center dark:border-slate-700">
          <p className="text-sm text-slate-500">
            No upcoming events yet.
          </p>
        </section>
      ) : (
        <section className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {events.map((e) => (
            <Link
              key={e.id}
              href={`/events/${e.slug}`}
              className="group overflow-hidden rounded-lg border border-slate-200 transition-shadow hover:shadow-lg dark:border-slate-800"
            >
              <div className="aspect-video bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center">
                <span className="text-3xl font-bold text-white">
                  {e.title.charAt(0)}
                </span>
              </div>
              <div className="p-4 space-y-2">
                <p className="text-xs uppercase tracking-wide text-slate-500">
                  {e.category}
                </p>
                <h2 className="text-lg font-semibold leading-tight group-hover:text-brand-600">
                  {e.title}
                </h2>
                {e.short_description && (
                  <p className="text-sm text-slate-600 dark:text-slate-400 line-clamp-2">
                    {e.short_description}
                  </p>
                )}
                <div className="flex justify-between text-sm text-slate-500 pt-2">
                  <span>{e.venue.name}, {e.venue.city}</span>
                  {e.next_occurrence && (
                    <span>{formatDate(e.next_occurrence.starts_at)}</span>
                  )}
                </div>
                {e.min_price_cents !== null && (
                  <p className="text-sm font-medium text-brand-600 pt-1">
                    From {formatMoney(e.min_price_cents)}
                  </p>
                )}
              </div>
            </Link>
          ))}
        </section>
      )}
    </div>
  );
}