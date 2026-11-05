package com.omnitix.ledger.domain

import com.omnitix.ledger.persistence.AccountRepository
import com.omnitix.ledger.persistence.JournalEntryRepository
import org.slf4j.LoggerFactory
import org.springframework.stereotype.Service
import org.springframework.transaction.annotation.Transactional
import java.time.Instant
import java.util.UUID

/**
 * LedgerPostingService is the single entry point for writing to the ledger.
 *
 * Every method that mutates accounting state goes through here, so we have
 * exactly one place where the double-entry invariant is enforced.
 */
@Service
class LedgerPostingService(
    private val accountRepo: AccountRepository,
    private val entryRepo: JournalEntryRepository,
) {
    private val log = LoggerFactory.getLogger(javaClass)

    /**
     * Post a balanced journal entry.
     *
     * If the entry has an idempotencyKey and an entry with that key was
     * already posted, this returns the existing entry instead of creating
     * a duplicate. Otherwise posts a new entry and returns it.
     *
     * @throws IllegalArgumentException if the entry doesn't balance, or
     *   references account codes that don't exist.
     */
    @Transactional
    fun post(entry: JournalEntry): JournalEntry {
        // ─── Idempotency: return existing entry if key already posted ───
        entry.idempotencyKey?.let { key ->
            entryRepo.findByIdempotencyKey(key)?.let { existing ->
                log.info(
                    "journal entry idempotent replay: key={} existing_id={}",
                    key, existing.id,
                )
                return existing
            }
        }

        entry.validateBalanced()

        // Verify every account code referenced actually exists.
        val referenced = entry.lines.map { it.accountCode }.toSet()
        val existing = accountRepo.findAllById(referenced).map { it.code }.toSet()
        val missing = referenced - existing
        require(missing.isEmpty()) {
            "Unknown account codes referenced: $missing"
        }

        val saved = entryRepo.save(entry)
        log.info(
            "journal entry posted: id={} ref={} type={} debits={} credits={}",
            saved.id, saved.reference, saved.entryType,
            saved.lines.sumOf { it.debitCents },
            saved.lines.sumOf { it.creditCents },
        )
        return saved
    }

    /**
     * Look up the original charge entry for an order, so refunds can reverse
     * the same account mix (revenue + fees + tax) rather than dumping
     * everything into REVENUE_TICKETS.
     *
     * Returns null if no such entry exists (e.g. an adjustment or a manual
     * charge that bypassed the normal pipeline).
     */
    @Transactional(readOnly = true)
    fun findChargeForOrder(orderId: String): JournalEntry? =
        entryRepo.findByReference("ORDER-$orderId").firstOrNull { it.entryType == JournalEntryType.PAYMENT_CHARGE }

    /**
     * Compute the balance of an account as of a point in time.
     *
     * Returns the net amount in cents with the account's natural sign:
     *   - ASSET / EXPENSE (normal debit): debits − credits
     *   - LIABILITY / REVENUE / EQUITY (normal credit): credits − debits
     */
    @Transactional(readOnly = true)
    fun balanceOf(accountCode: String, asOf: Instant = Instant.now()): Long {
        val account = accountRepo.findById(accountCode).orElseThrow {
            IllegalArgumentException("Account not found: $accountCode")
        }

        // Fetch all entries posted at or before the given time.
        // In production we'd use an aggregated SQL view or a materialized balance
        // table for performance. For v1 correctness, compute in memory.
        val entries = entryRepo.findInDateRange(Instant.EPOCH, asOf)

        val (debits, credits) = entries
            .flatMap { it.lines }
            .filter { it.accountCode == accountCode }
            .fold(0L to 0L) { acc, line ->
                acc.first + line.debitCents to acc.second + line.creditCents
            }

        return when (account.accountType.normalBalance) {
            BalanceSide.DEBIT -> debits - credits
            BalanceSide.CREDIT -> credits - debits
        }
    }

    /**
     * Generate a ULID for a new journal entry or line.
     * In v1 we use a random-based ULID (no monotonic clock across processes).
     */
    fun newId(): String = ulid()

    // ─── Internal ───────────────────────────────────────────

    private fun ulid(): String {
        // 26-char ULID: 48-bit timestamp + 80-bit randomness, Crockford Base32
        val ts = System.currentTimeMillis()
        val tsPart = encodeBase32(ts, 10)
        val randPart = UUID.randomUUID().toString().replace("-", "").take(16).uppercase()
            .let { it.filter { c -> c.isLetterOrDigit() }.take(16) }
        return (tsPart + randPart).take(26).uppercase()
    }

    private fun encodeBase32(value: Long, length: Int): String {
        val alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
        val sb = StringBuilder()
        var v = value
        repeat(length) {
            sb.insert(0, alphabet[(v and 31).toInt()])
            v = v shr 5
        }
        return sb.toString()
    }
}