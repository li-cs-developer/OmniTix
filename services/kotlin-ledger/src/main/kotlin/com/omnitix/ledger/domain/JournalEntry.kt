package com.omnitix.ledger.domain

import jakarta.persistence.*
import java.time.Instant

/**
 * A journal entry — the header of a double-entry transaction.
 *
 * Every entry must have at least two lines (see JournalLine), and the sum
 * of all debits must equal the sum of all credits. This invariant is what
 * makes the ledger self-checking: if debits ≠ credits, someone made an
 * error and the transaction will refuse to save.
 */
@Entity
@Table(
    name = "journal_entries",
    schema = "ledger",
    indexes = [
        Index(name = "idx_journal_entries_reference", columnList = "reference"),
        Index(name = "idx_journal_entries_posted_at", columnList = "posted_at"),
        Index(name = "idx_journal_entries_entry_type", columnList = "entry_type"),
        Index(name = "idx_journal_entries_idempotency_key", columnList = "idempotency_key"),
    ]
)
class JournalEntry(
    @Id
    @Column(name = "id", length = 26, nullable = false)
    var id: String,

    @Column(name = "reference", nullable = false, length = 128)
    var reference: String,

    @Column(name = "description", nullable = false, length = 512)
    var description: String,

    @Enumerated(EnumType.STRING)
    @Column(name = "entry_type", nullable = false, length = 32)
    var entryType: JournalEntryType,

    @Column(name = "currency", nullable = false, length = 3)
    var currency: String = "USD",

    @Column(name = "posted_at", nullable = false)
    var postedAt: Instant = Instant.now(),

    @Column(name = "created_at", nullable = false, updatable = false)
    var createdAt: Instant = Instant.now(),

    // NEW: idempotency key (nullable — normal entries don't need one)
    @Column(name = "idempotency_key", length = 64, nullable = true)
    var idempotencyKey: String? = null,

    @OneToMany(
        mappedBy = "entry",
        cascade = [CascadeType.ALL],
        orphanRemoval = true,
        fetch = FetchType.EAGER,
    )
    var lines: MutableList<JournalLine> = mutableListOf(),
) {
    /**
     * Add a line to this journal entry.
     */
    fun addLine(line: JournalLine) {
        line.entry = this
        lines.add(line)
    }

    /**
     * Validate the double-entry invariant.
     * Called before persisting to catch programming errors early.
     */
    fun validateBalanced() {
        require(lines.size >= 2) {
            "Journal entry must have at least 2 lines; got ${lines.size}"
        }

        val totalDebits = lines.sumOf { it.debitCents }
        val totalCredits = lines.sumOf { it.creditCents }

        require(totalDebits == totalCredits) {
            "Journal entry is unbalanced: debits=$totalDebits credits=$totalCredits"
        }
        require(totalDebits > 0) {
            "Journal entry has zero amount"
        }
    }

    override fun equals(other: Any?): Boolean =
        this === other || (other is JournalEntry && other.id == id)

    override fun hashCode(): Int = id.hashCode()
}

/**
 * Types of journal entries we produce. Used for reporting and filtering.
 */
enum class JournalEntryType {
    PAYMENT_CHARGE,      // buyer paid for tickets
    REFUND,              // buyer was refunded
    PAYOUT,              // organizer was paid out
    FEE_REVENUE,         // platform fee collected
    TAX_ACCRUAL,         // sales tax collected
    ADJUSTMENT,          // manual adjustment (admin)
}