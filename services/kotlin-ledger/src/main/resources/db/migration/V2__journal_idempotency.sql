-- ═══════════════════════════════════════════════════════════
-- OmniTix Ledger — Journal entry idempotency
--
-- A client may retry a charge. Without a unique constraint on
-- idempotency_key, that retry would post a SECOND journal entry
-- (double-book the revenue). We add a nullable unique column so:
--   - normal entries (no key supplied) don't conflict
--   - entries with a key are deduplicated at the DB level
-- ═══════════════════════════════════════════════════════════

ALTER TABLE ledger.journal_entries
    ADD COLUMN idempotency_key VARCHAR(64);

CREATE UNIQUE INDEX idx_journal_entries_idem
    ON ledger.journal_entries (idempotency_key)
    WHERE idempotency_key IS NOT NULL;