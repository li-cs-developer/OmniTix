package com.omnitix.ledger.domain

/**
 * The five fundamental account types in double-entry bookkeeping.
 *
 * - ASSET:     things you own (Cash, Bank)
 * - LIABILITY: things you owe (Refunds Payable, Tax Payable, Organizer Payable)
 * - REVENUE:   money earned (Ticket Sales, Fees)
 * - EXPENSE:   money spent (Payment Processing)
 * - EQUITY:    owner's stake (rarely used in ticketing)
 *
 * Normal balance rules:
 *   - ASSET, EXPENSE  → normal DEBIT balance (increase with debit)
 *   - LIABILITY, REVENUE, EQUITY → normal CREDIT balance (increase with credit)
 */
enum class AccountType(val normalBalance: BalanceSide) {
    ASSET(BalanceSide.DEBIT),
    LIABILITY(BalanceSide.CREDIT),
    REVENUE(BalanceSide.CREDIT),
    EXPENSE(BalanceSide.DEBIT),
    EQUITY(BalanceSide.CREDIT),
}

enum class BalanceSide {
    DEBIT,
    CREDIT,
}