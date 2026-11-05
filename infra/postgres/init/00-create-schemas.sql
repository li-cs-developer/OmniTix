-- ═══════════════════════════════════════════════════════════
-- OmniTix — PostgreSQL bootstrap
-- Executed once on first container start via
-- /docker-entrypoint-initdb.d/00-create-schemas.sql
-- ═══════════════════════════════════════════════════════════

-- ─── Extensions ─────────────────────────────────────────
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "btree_gin";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- ─── Schemas ────────────────────────────────────────────
CREATE SCHEMA IF NOT EXISTS app;
COMMENT ON SCHEMA app IS 'Laravel core: users, organizers, venues, events, tickets';

CREATE SCHEMA IF NOT EXISTS ledger;
COMMENT ON SCHEMA ledger IS 'Kotlin financial ledger: journal entries, payouts, refunds';

CREATE SCHEMA IF NOT EXISTS reporting;
COMMENT ON SCHEMA reporting IS 'Read-only views for Metabase / BI';

-- ─── Ledger role ────────────────────────────────────────
-- The ledger service owns the `ledger` schema exclusively.
-- Laravel MUST NOT write to it.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'omnitix_ledger') THEN
    CREATE ROLE omnitix_ledger LOGIN PASSWORD 'omnitix_ledger_dev_password';
  END IF;
END
$$;

GRANT ALL ON SCHEMA ledger TO omnitix_ledger;
GRANT USAGE ON SCHEMA app TO omnitix_ledger;
GRANT USAGE ON SCHEMA reporting TO omnitix_ledger;

ALTER DEFAULT PRIVILEGES IN SCHEMA ledger
  GRANT ALL ON TABLES TO omnitix_ledger;
ALTER DEFAULT PRIVILEGES IN SCHEMA ledger
  GRANT ALL ON SEQUENCES TO omnitix_ledger;

-- ─── Audit helper ───────────────────────────────────────
-- A generic updated_at trigger we can attach to any table.
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;