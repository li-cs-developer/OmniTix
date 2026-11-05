import { z } from 'zod';

const publicSchema = z.object({
  NEXT_PUBLIC_LARAVEL_URL: z.string().url(),
  NEXT_PUBLIC_INVENTORY_URL: z.string().url(),
  NEXT_PUBLIC_WS_URL: z.string().url(),
  NEXT_PUBLIC_WS_PATH: z.string().default('/ws'),
});

const serverSchema = z.object({
  LARAVEL_INTERNAL_URL: z.string().url(),
  INVENTORY_INTERNAL_URL: z.string().url(),
});

/**
 * Validate and parse public env vars.
 *
 * On failure, logs the exact missing/malformed fields via Zod's
 * `.format()` before throwing. The log lands in the Next.js dev
 * terminal (or the container's stderr in production) and names the
 * specific `NEXT_PUBLIC_*` keys that are wrong — turning a generic
 * "there's a server error" into "you forgot to set
 * NEXT_PUBLIC_WS_URL in services/nextjs-storefront/.env".
 */
function publicEnvFromProcess() {
  const parsed = publicSchema.safeParse({
    NEXT_PUBLIC_LARAVEL_URL: process.env.NEXT_PUBLIC_LARAVEL_URL,
    NEXT_PUBLIC_INVENTORY_URL: process.env.NEXT_PUBLIC_INVENTORY_URL,
    NEXT_PUBLIC_WS_URL: process.env.NEXT_PUBLIC_WS_URL,
    NEXT_PUBLIC_WS_PATH: process.env.NEXT_PUBLIC_WS_PATH,
  });
  if (!parsed.success) {
    console.error(
      '[env] Missing NEXT_PUBLIC_* env vars. Check services/nextjs-storefront/.env:\n',
      parsed.error.format(),
    );
    throw new Error(
      'Environment validation failed. Required NEXT_PUBLIC_* vars are missing from .env. ' +
      'See the console log above for which ones.',
    );
  }
  return parsed.data;
}

// Public env — safe to expose to the browser.
export const publicEnv = publicEnvFromProcess();

// Server-only env — must never be imported from client components.
export const serverEnv = () => {
  if (typeof window !== 'undefined') {
    throw new Error('serverEnv() must not be called from the browser');
  }
  return serverSchema.parse({
    LARAVEL_INTERNAL_URL: process.env.LARAVEL_INTERNAL_URL,
    INVENTORY_INTERNAL_URL: process.env.INVENTORY_INTERNAL_URL,
  });
};