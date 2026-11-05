package com.omnitix.ledger.domain

import jakarta.persistence.*
import java.time.Instant

/**
 * A single line of a journal entry.
 *
 * Each line is either a DEBIT or a CREDIT — never both. We model them as
 * separate integer columns (debit_cents, credit_cents) rather than a signed
 * amount, because that's how accounting is actually done and it makes
 * auditing trivial: `SUM(debit_cents) = SUM(credit_cents)` per entry.
 *
 * Exactly one of debitCents/creditCents must be > 0.
 */
@Entity
@Table(
    name = "journal_lines",
    schema = "ledger",
    indexes = [
        Index(name = "idx_journal_lines_entry", columnList = "entry_id"),
        Index(name = "idx_journal_lines_account", columnList = "account_code"),
    ]
)
class JournalLine(
    @Id
    @Column(name = "id", length = 26, nullable = false)
    var id: String,

    @ManyToOne(fetch = FetchType.LAZY)
    @JoinColumn(name = "entry_id", nullable = false)
    var entry: JournalEntry? = null,

    @Column(name = "account_code", nullable = false, length = 64)
    var accountCode: String,

    @Column(name = "debit_cents", nullable = false)
    var debitCents: Long = 0L,

    @Column(name = "credit_cents", nullable = false)
    var creditCents: Long = 0L,

    @Column(name = "memo", length = 512)
    var memo: String? = null,

    @Column(name = "created_at", nullable = false, updatable = false)
    var createdAt: Instant = Instant.now(),
) {
    init {
        require(debitCents >= 0) { "debitCents must be non-negative" }
        require(creditCents >= 0) { "creditCents must be non-negative" }
        require((debitCents > 0) xor (creditCents > 0)) {
            "Journal line must have exactly one non-zero side: debit=$debitCents credit=$creditCents"
        }
    }

    /** True if this line is a debit. */
    val isDebit: Boolean get() = debitCents > 0

    /** True if this line is a credit. */
    val isCredit: Boolean get() = creditCents > 0

    /** The amount, regardless of side. */
    val amountCents: Long get() = if (isDebit) debitCents else creditCents

    override fun equals(other: Any?): Boolean =
        this === other || (other is JournalLine && other.id == id)

    override fun hashCode(): Int = id.hashCode()

    override fun toString(): String {
        val side = if (isDebit) "DR" else "CR"
        return "JournalLine($accountCode $side $amountCents)"
    }

    companion object {
        /** Factory for a debit line. */
        fun debit(
            id: String,
            accountCode: String,
            amountCents: Long,
            memo: String? = null,
        ): JournalLine = JournalLine(
            id = id,
            accountCode = accountCode,
            debitCents = amountCents,
            creditCents = 0L,
            memo = memo,
        )

        /** Factory for a credit line. */
        fun credit(
            id: String,
            accountCode: String,
            amountCents: Long,
            memo: String? = null,
        ): JournalLine = JournalLine(
            id = id,
            accountCode = accountCode,
            debitCents = 0L,
            creditCents = amountCents,
            memo = memo,
        )
    }
}