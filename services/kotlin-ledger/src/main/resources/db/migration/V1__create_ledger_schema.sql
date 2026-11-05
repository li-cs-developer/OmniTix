-- ═══════════════════════════════════════════════════════════
-- OmniTix Ledger — Initial schema
-- Only runs in the `ledger` schema. Never touches `app`.
-- ═══════════════════════════════════════════════════════════

-- ─── Accounts ───────────────────────────────────────────
CREATE TABLE ledger.accounts (
    code         VARCHAR(64) PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    account_type VARCHAR(16)  NOT NULL,
    currency     VARCHAR(3)   NOT NULL DEFAULT 'USD',
    is_system    BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT accounts_type_check CHECK (
        account_type IN ('ASSET', 'LIABILITY', 'REVENUE', 'EXPENSE', 'EQUITY')
    )
);

CREATE INDEX idx_accounts_type ON ledger.accounts(account_type);

-- ─── Journal entries ────────────────────────────────────
CREATE TABLE ledger.journal_entries (
    id           VARCHAR(26) PRIMARY KEY,
    reference    VARCHAR(128) NOT NULL,
    description  VARCHAR(512) NOT NULL,
    entry_type   VARCHAR(32)  NOT NULL,
    currency     VARCHAR(3)   NOT NULL DEFAULT 'USD',
    posted_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT journal_entries_type_check CHECK (
        entry_type IN (
            'PAYMENT_CHARGE', 'REFUND', 'PAYOUT',
            'FEE_REVENUE', 'TAX_ACCRUAL', 'ADJUSTMENT'
        )
    )
);

CREATE INDEX idx_journal_entries_reference ON ledger.journal_entries(reference);
CREATE INDEX idx_journal_entries_posted_at ON ledger.journal_entries(posted_at DESC);
CREATE INDEX idx_journal_entries_entry_type ON ledger.journal_entries(entry_type);

-- ─── Journal lines ──────────────────────────────────────
CREATE TABLE ledger.journal_lines (
    id           VARCHAR(26)  PRIMARY KEY,
    entry_id     VARCHAR(26)  NOT NULL REFERENCES ledger.journal_entries(id) ON DELETE CASCADE,
    account_code VARCHAR(64)  NOT NULL REFERENCES ledger.accounts(code),
    debit_cents  BIGINT       NOT NULL DEFAULT 0,
    credit_cents BIGINT       NOT NULL DEFAULT 0,
    memo         VARCHAR(512),
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT journal_lines_debit_nonneg  CHECK (debit_cents >= 0),
    CONSTRAINT journal_lines_credit_nonneg CHECK (credit_cents >= 0),
    CONSTRAINT journal_lines_exactly_one_side CHECK (
        (debit_cents > 0 AND credit_cents = 0)
        OR (debit_cents = 0 AND credit_cents > 0)
    )
);

CREATE INDEX idx_journal_lines_entry ON ledger.journal_lines(entry_id);
CREATE INDEX idx_journal_lines_account ON ledger.journal_lines(account_code);

-- ─── Seed chart of accounts ─────────────────────────────
INSERT INTO ledger.accounts (code, name, account_type, is_system) VALUES
    ('ASSET_CASH',                     'Cash',                        'ASSET',     TRUE),
    ('ASSET_STRIPE_BALANCE',           'Stripe Balance',              'ASSET',     TRUE),
    ('LIABILITY_REFUNDS_PAYABLE',      'Refunds Payable',             'LIABILITY', TRUE),
    ('LIABILITY_TAX_PAYABLE',          'Sales Tax Payable',           'LIABILITY', TRUE),
    ('LIABILITY_ORGANIZER_PAYABLE',    'Organizer Payable',           'LIABILITY', TRUE),
    ('REVENUE_TICKETS',                'Ticket Revenue',              'REVENUE',   TRUE),
    ('REVENUE_FEES',                   'Fee Revenue',                 'REVENUE',   TRUE),
    ('EXPENSE_PAYMENT_PROCESSING',     'Payment Processing Fees',     'EXPENSE',   TRUE);