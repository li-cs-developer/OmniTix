import { z } from 'zod';
import { publicEnv, serverEnv } from './env';

// ─────────────────────────────────────────────────────────
// Base client
// ─────────────────────────────────────────────────────────

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

interface RequestOptions extends Omit<RequestInit, 'body'> {
  body?: unknown;
  /** If true, uses server-side internal URL and forwards cookies. */
  server?: boolean;
}

async function request<T>(
  path: string,
  schema: z.ZodType<T>,
  options: RequestOptions = {},
): Promise<T> {
  const { body, server, headers, ...rest } = options;

  const baseUrl = server
    ? serverEnv().LARAVEL_INTERNAL_URL
    : publicEnv.NEXT_PUBLIC_LARAVEL_URL;

  const url = `${baseUrl}${path}`;

  const finalHeaders = new Headers(headers);
  if (body !== undefined && !finalHeaders.has('Content-Type')) {
    finalHeaders.set('Content-Type', 'application/json');
  }
  finalHeaders.set('Accept', 'application/json');

  const res = await fetch(url, {
    ...rest,
    headers: finalHeaders,
    body: body !== undefined ? JSON.stringify(body) : undefined,
    // Always — never cache API responses. `revalidate = 0` on the page
    // already forces re-fetching, but a caller passing `cache: 'force-cache'`
    // or `next: { revalidate: N }` in `options` could otherwise override
    // the page-level setting. Server-side rendered pages with stale data
    // are worse than slightly slower responses.
    cache: 'no-store',
  });

  const raw = await res.text();
  let parsed: unknown = null;
  if (raw.length > 0) {
    try {
      parsed = JSON.parse(raw);
    } catch {
      throw new ApiError(res.status, 'invalid_json', 'Response was not valid JSON');
    }
  }

  if (!res.ok) {
    const code =
      typeof parsed === 'object' && parsed !== null && 'error' in parsed
        ? String((parsed as any).error)
        : `http_${res.status}`;
    const message =
      typeof parsed === 'object' && parsed !== null && 'message' in parsed
        ? String((parsed as any).message)
        : `Request failed: ${res.status} ${res.statusText}`;
    throw new ApiError(res.status, code, message);
  }

  const result = schema.safeParse(parsed);
  if (!result.success) {
    const issues = result.error.issues.map((i) => ({
      path: i.path.join('.'),
      message: i.message,
      code: i.code,
    }));
    console.error('Zod validation failed at', path);
    console.error('Issues:', JSON.stringify(issues, null, 2));
    console.error('Received payload (first 500 chars):', JSON.stringify(parsed).slice(0, 500));
    throw new ApiError(
      res.status,
      'schema_mismatch',
      `API returned unexpected shape: ${issues[0]?.path}: ${issues[0]?.message}`,
    );
  }
  return result.data;
}

// ─────────────────────────────────────────────────────────
// Reusable sub-schemas
// ─────────────────────────────────────────────────────────

/**
 * JSON-bag schema.
 *
 * Accepts any value. The `[]` → `{}` normalization happens at the API
 * boundary via the `normalizeJsonBag` helper below.
 *
 * Using `z.custom<T>` with an explicit type parameter gives TypeScript
 * a single, unambiguous output type (`Record<string, unknown>`) — no
 * union, no transform inference, no `.extend()` bugs.
 */
const JsonBag = z.custom<Record<string, unknown>>(
  (v) => v === undefined || v === null || typeof v === 'object',
  { message: 'Expected an object, null, or undefined' },
);

/**
 * Normalize a JSON bag value: arrays (including empty ones) become `{}`.
 * Runs on the *input* before Zod validation, so the schema only ever
 * sees a proper object.
 */
function normalizeJsonBag(v: unknown): Record<string, unknown> {
  if (v === null || v === undefined) return {};
  if (Array.isArray(v)) return {};
  if (typeof v === 'object') return v as Record<string, unknown>;
  return {};
}

/**
 * Preprocessed JSON-bag field schema.
 *
 * Wraps `JsonBag` in `z.preprocess` so arrays arriving from PHP are
 * normalized to objects before validation. The `as z.ZodType<...>` cast
 * collapses Zod's input/output union into a single output type, which
 * TypeScript needs to satisfy field assignments inside object schemas.
 */
const JsonBagField = z.preprocess(
  normalizeJsonBag,
  JsonBag,
) as z.ZodType<Record<string, unknown>>;

// ─────────────────────────────────────────────────────────
// Domain types (validated by Zod)
// ─────────────────────────────────────────────────────────

export const EventSummarySchema = z.object({
  id: z.string(),
  slug: z.string(),
  title: z.string(),
  short_description: z.string().nullable(),
  hero_image_url: z.string().nullable(),
  category: z.string(),
  status: z.string(),
  sales_start_at: z.string().nullable(),
  sales_end_at: z.string().nullable(),
  venue: z.object({
    id: z.string(),
    name: z.string(),
    city: z.string().nullable(),
  }),
  next_occurrence: z
    .object({
      id: z.string(),
      starts_at: z.string(),
      doors_open_at: z.string().nullable(),
    })
    .nullable(),
  min_price_cents: z.number().int().nonnegative().nullable(),
  max_price_cents: z.number().int().nonnegative().nullable(),
});

export type EventSummary = z.infer<typeof EventSummarySchema>;

// NOTE: Built as a flat z.object() rather than EventSummarySchema.extend(...)
// to sidestep Zod's .extend() type-inference loss for transform-based fields.
export const EventDetailSchema = z.object({
  id: z.string(),
  slug: z.string(),
  title: z.string(),
  short_description: z.string().nullable(),
  hero_image_url: z.string().nullable(),
  category: z.string(),
  status: z.string(),
  sales_start_at: z.string().nullable(),
  sales_end_at: z.string().nullable(),
  next_occurrence: z
    .object({
      id: z.string(),
      starts_at: z.string(),
      doors_open_at: z.string().nullable(),
    })
    .nullable(),
  min_price_cents: z.number().int().nonnegative().nullable(),
  max_price_cents: z.number().int().nonnegative().nullable(),

  description: z.string().nullable(),
  age_restriction: z.number().int().min(0),
  performer_name: z.string().nullable(),
  max_tickets_per_user: z.number().int().positive(),
  venue: z.object({
    id: z.string(),
    name: z.string(),
    city: z.string().nullable(),
    state: z.string().nullable(),
    timezone: z.string(),
  }),
  seat_map: z.object({
    id: z.string(),
    width: z.number().int().positive(),
    height: z.number().int().positive(),
    meta: JsonBagField,
    ga_zones: z.array(
      z.object({
        id: z.string(),
        code: z.string(),
        name: z.string(),
        capacity: z.number().int().nonnegative(),
        visual: JsonBagField,
      }),
    ),
  }),
  sections: z.array(
    z.object({
      id: z.string(),
      code: z.string(),
      name: z.string(),
      kind: z.enum(['seated', 'ga', 'box', 'table', 'accessible']),
      visual: JsonBagField,
    }),
  ),
  price_tiers: z.array(
    z.object({
      id: z.string(),
      name: z.string(),
      price_cents: z.number().int().nonnegative(),
      currency: z.string().length(3),
      color: z.string(),
    }),
  ),
});

export type EventDetail = z.infer<typeof EventDetailSchema>;

export const SeatsResponseSchema = z.object({
  seat_map_id: z.string(),
  seats: z.array(
    z.object({
      id: z.string(),
      section: z.string(),
      row: z.string(),
      number: z.string(),
      label: z.string(),
      x: z.number(),
      y: z.number(),
      rotation: z.number(),
      attributes: JsonBagField,
    }),
  ),
});

export type SeatsResponse = z.infer<typeof SeatsResponseSchema>;

export const SeatStateResponseSchema = z.object({
  occurrence_id: z.string(),
  sold: z.array(z.string()),
  held: z.array(z.string()),
});

export type SeatStateResponse = z.infer<typeof SeatStateResponseSchema>;

export const SeatLayoutResponseSchema = z.object({
  seat_map_id: z.string(),
  width: z.number().int().positive(),
  height: z.number().int().positive(),
  sections: z.array(
    z.object({
      id: z.string(),
      code: z.string(),
      name: z.string(),
      kind: z.enum(['seated', 'ga', 'box', 'table', 'accessible']),
      visual: JsonBagField,
    }),
  ),
  seats: z.array(
    z.object({
      id: z.string(),
      section_code: z.string(),
      row: z.string(),
      number: z.string(),
      label: z.string(),
      x: z.number(),
      y: z.number(),
      rotation: z.number(),
      attributes: JsonBagField,
    }),
  ),
  ga_zones: z.array(
    z.object({
      id: z.string(),
      code: z.string(),
      name: z.string(),
      capacity: z.number().int().nonnegative(),
      visual: JsonBagField,
    }),
  ),
});

export type SeatLayoutResponse = z.infer<typeof SeatLayoutResponseSchema>;

export const HoldSeatResponseSchema = z.object({
  data: z.object({
    token: z.string(),
    seat_id: z.string(),
    expires_at: z.string(),
    user_id: z.string(),
  }),
});

export type HoldSeatResponse = z.infer<typeof HoldSeatResponseSchema>;

// P2-16: GA hold response.
export const HoldGaResponseSchema = z.object({
  data: z.object({
    token: z.string(),
    ga_zone_id: z.string(),
    quantity: z.number().int().positive(),
    expires_at: z.string(),
    remaining_capacity: z.number().int().nonnegative(),
  }),
});

export type HoldGaResponse = z.infer<typeof HoldGaResponseSchema>;

// ─────────────────────────────────────────────────────────
// Public API functions
// ─────────────────────────────────────────────────────────

export const api = {
  async listEvents(opts: { server?: boolean } = {}): Promise<EventSummary[]> {
    const schema = z.object({ data: z.array(EventSummarySchema) });
    const result = await request('/api/v1/events', schema, {
      server: opts.server,
    });
    return result.data;
  },

  async getEvent(slug: string, opts: { server?: boolean } = {}): Promise<EventDetail> {
    const schema = z.object({ data: EventDetailSchema });
    const result = await request(`/api/v1/events/${slug}`, schema, {
      server: opts.server,
    });
    return result.data;
  },

  async getSeats(
    eventId: string,
    opts: { server?: boolean } = {},
  ): Promise<SeatsResponse> {
    const schema = z.object({ data: SeatsResponseSchema });
    const result = await request(`/api/v1/events/${eventId}/seats`, schema, {
      server: opts.server,
    });
    return result.data;
  },

  async getSeatState(occurrenceId: string): Promise<SeatStateResponse> {
    const schema = z.object({ data: SeatStateResponseSchema });
    const result = await request(
      `/api/v1/seats/states?occurrence_id=${encodeURIComponent(occurrenceId)}`,
      schema,
      { method: 'GET' },
    );
    return result.data;
  },

  async getSeatLayout(
    eventId: string,
    opts: { server?: boolean } = {},
  ): Promise<SeatLayoutResponse> {
    const schema = z.object({ data: SeatLayoutResponseSchema });
    const result = await request(`/api/v1/events/${eventId}/seats`, schema, {
      server: opts.server,
    });
    return result.data;
  },

  async holdSeat(params: {
    occurrenceId: string;
    seatId: string;
    userId: string;
  }): Promise<HoldSeatResponse['data']> {
    const schema = HoldSeatResponseSchema;
    const result = await request('/api/v1/seats/hold', schema, {
      method: 'POST',
      body: {
        occurrence_id: params.occurrenceId,
        seat_id: params.seatId,
        user_id: params.userId,
      },
    });
    return result.data;
  },

  // P2-16: Hold N GA tickets in a zone.
  async holdGaZone(params: {
    occurrenceId: string;
    gaZoneId: string;
    quantity: number;
    userId: string;
  }): Promise<HoldGaResponse['data']> {
    const schema = HoldGaResponseSchema;
    const result = await request('/api/v1/ga/hold', schema, {
      method: 'POST',
      body: {
        occurrence_id: params.occurrenceId,
        ga_zone_id: params.gaZoneId,
        quantity: params.quantity,
        user_id: params.userId,
      },
    });
    return result.data;
  },

  async releaseSeat(params: {
    token: string;
    userId: string;
  }): Promise<void> {
    const schema = z.object({
      data: z.union([z.object({}).passthrough(), z.array(z.unknown())]),
    });
    await request('/api/v1/seats/release', schema, {
      method: 'POST',
      body: {
        token: params.token,
        user_id: params.userId,
      },
    });
  },

  /** Checkout: create order, charge, confirm holds, issue tickets. */
  async checkout(params: {
    occurrenceId: string;
    userId: string;
    buyerEmail: string;
    idempotencyKey: string;
    promoCode?: string;
    items: Array<{
      seatId: string;
      holdToken: string;
      priceTierId: string;
      priceCents: number;
    }>;
  }): Promise<{
    orderId: string;
    reference: string;
    totalCents: number;
    currency: string;
    status: string;
    tickets: Array<{
      id: string;
      ticketNumber: string;
      seatId: string;
      qrPayload: string | null;
      qrSignature: string | null;
      signerKeyId: string | null;
      status: string;
    }>;
  }> {
    const schema = z.object({
      data: z.object({
        order_id: z.string(),
        reference: z.string(),
        total_cents: z.number(),
        currency: z.string(),
        status: z.string(),
        tickets: z.array(
          z.object({
            id: z.string(),
            ticket_number: z.string(),
            seat_id: z.string(),
            qr_payload: z.string().nullable(),
            qr_signature: z.string().nullable(),
            signer_key_id: z.string().nullable(),
            status: z.string(),
          }),
        ),
      }),
    });
    const result = await request('/api/v1/orders/checkout', schema, {
      method: 'POST',
      body: {
        occurrence_id: params.occurrenceId,
        user_id: params.userId,
        buyer_email: params.buyerEmail,
        idempotency_key: params.idempotencyKey,
        promo_code: params.promoCode ?? null,
        seats: params.items.map((i) => ({
          seat_id: i.seatId,
          hold_token: i.holdToken,
          price_tier_id: i.priceTierId,
          price_cents: i.priceCents,
        })),
      },
    });
    return {
      orderId: result.data.order_id,
      reference: result.data.reference,
      totalCents: result.data.total_cents,
      currency: result.data.currency,
      status: result.data.status,
      tickets: result.data.tickets.map((t) => ({
        id: t.id,
        ticketNumber: t.ticket_number,
        seatId: t.seat_id,
        qrPayload: t.qr_payload,
        qrSignature: t.qr_signature,
        signerKeyId: t.signer_key_id,
        status: t.status,
      })),
    };
  },
};