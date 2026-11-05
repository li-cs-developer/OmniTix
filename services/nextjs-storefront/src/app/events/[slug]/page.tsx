import Link from 'next/link';
import { notFound } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import { SeatMap } from '@/components/seatmap/SeatMap';
import { GaZonePicker } from '@/components/seatmap/GaZonePicker';
import { CartSidebar } from './CartSidebar';

// Force every request to re-fetch from Laravel.
// Without `revalidate = 0`, Next.js can bake the build-time response into
// the standalone output when the fetch fails during `next build`.
export const dynamic = 'force-dynamic';
export const revalidate = 0;

interface PageProps {
  params: { slug: string };
}

// For v1, generate a guest user ID per browser session.
// In v2 this comes from auth.
function guestUserId(): string {
  return 'guest-' + Math.random().toString(36).slice(2, 10);
}

export default async function EventDetailPage({ params }: PageProps) {
  let event;
  try {
    event = await api.getEvent(params.slug, { server: true });
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) {
      notFound();
    }
    throw err;
  }

  const nextOccurrence = event.next_occurrence;

  // Build a section → pricing map.
  // For v1: assume one price tier per section (the cheapest matching tier).
  // For v2: multi-tiered sections with user picks.
  const sectionPricing: Record<string, { tierId: string; priceCents: number }> = {};
  if (event.price_tiers.length > 0) {
    const cheapest = [...event.price_tiers].sort(
      (a, b) => a.price_cents - b.price_cents,
    )[0];
    for (const section of event.sections) {
      sectionPricing[section.code] = {
        tierId: cheapest.id,
        priceCents: cheapest.price_cents,
      };
    }
  }

  // Build a GA-zone → pricing map.
  // Must be a plain serializable object — React Server Components cannot
  // pass functions (like a `priceForZone` closure) to Client Components.
  // The cheapest tier is used as the default price for every zone in v1.
  const gaZones = event.seat_map?.ga_zones ?? [];
  const cheapestTier = [...event.price_tiers].sort(
    (a, b) => a.price_cents - b.price_cents,
  )[0];
  const zonePricing: Record<string, { tierId: string; priceCents: number } | null> =
    Object.fromEntries(
      gaZones.map((z) => [
        z.id,
        cheapestTier
          ? { tierId: cheapestTier.id, priceCents: cheapestTier.price_cents }
          : null,
      ]),
    );

  const userId = guestUserId();

  return (
    <div className="space-y-8">
      <Link href="/" className="text-sm text-slate-500 hover:text-slate-900">
        ← All events
      </Link>

      <section className="grid gap-6 lg:grid-cols-[2fr,1fr]">
        <div className="space-y-4">
          <p className="text-xs uppercase tracking-wide text-brand-600">
            {event.category}
          </p>
          <h1 className="text-4xl font-bold tracking-tight">{event.title}</h1>
          {event.performer_name && (
            <p className="text-lg text-slate-600 dark:text-slate-400">
              {event.performer_name}
            </p>
          )}
          {event.description && (
            <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
              {event.description}
            </p>
          )}
          {event.age_restriction > 0 && (
            <p className="text-sm font-medium text-amber-600">
              {event.age_restriction}+ only
            </p>
          )}
        </div>

        <aside className="space-y-4 rounded-lg border border-slate-200 p-6 dark:border-slate-800">
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-500">
              Venue
            </p>
            <p className="font-medium">{event.venue.name}</p>
            {event.venue.city && (
              <p className="text-sm text-slate-600 dark:text-slate-400">
                {event.venue.city}
                {event.venue.state ? `, ${event.venue.state}` : ''}
              </p>
            )}
          </div>

          {nextOccurrence && (
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-500">
                Next show
              </p>
              <p className="font-medium">
                {formatDateTime(nextOccurrence.starts_at, event.venue.timezone)}
              </p>
              {nextOccurrence.doors_open_at && (
                <p className="text-sm text-slate-600 dark:text-slate-400">
                  Doors:{' '}
                  {formatDateTime(
                    nextOccurrence.doors_open_at,
                    event.venue.timezone,
                  )}
                </p>
              )}
            </div>
          )}

          {event.price_tiers.length > 0 && (
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-500 mb-2">
                Pricing
              </p>
              <ul className="space-y-1.5">
                {event.price_tiers.map((tier) => (
                  <li key={tier.id} className="flex justify-between text-sm">
                    <span className="flex items-center gap-2">
                      <span
                        className="inline-block h-2 w-2 rounded-full"
                        style={{ backgroundColor: tier.color }}
                      />
                      {tier.name}
                    </span>
                    <span className="font-medium">
                      {formatMoney(tier.price_cents, tier.currency)}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </aside>
      </section>

      {nextOccurrence ? (
        <section className="space-y-4">
          <h2 className="text-2xl font-semibold">Pick your seats</h2>
          <GaZonePicker
            occurrenceId={nextOccurrence.id}
            userId={userId}
            zones={gaZones}
            zonePricing={zonePricing}
          />
          <div className="grid gap-6 lg:grid-cols-[2fr,1fr]">
            <SeatMap
              eventId={event.id}
              occurrenceId={nextOccurrence.id}
              sectionPricing={sectionPricing}
              userId={userId}
            />
            <div className="lg:sticky lg:top-4 lg:self-start">
              <CartSidebar
                eventId={event.id}
                occurrenceId={nextOccurrence.id}
                userId={userId}
              />
            </div>
          </div>
        </section>
      ) : (
        <section className="rounded-lg border border-dashed border-slate-300 p-12 text-center dark:border-slate-700">
          <p className="text-sm text-slate-500">
            No upcoming occurrences for this event.
          </p>
        </section>
      )}
    </div>
  );
}