package com.omnitix.ledger.domain

import com.omnitix.ledger.kafka.LedgerEventPublisher
import org.slf4j.LoggerFactory
import org.springframework.stereotype.Service
import org.springframework.transaction.annotation.Transactional
import java.time.Instant
import java.time.temporal.ChronoUnit
import java.util.UUID

/**
 * PayoutService settles organizer balances.
 *
 * Given a period [from, to), and an organizer, this:
 *   1. Reads the organizer's accumulated balance from LIABILITY_ORGANIZER_PAYABLE
 *   2. Posts:
 *        DR LIABILITY_ORGANIZER_PAYABLE   (reduce what we owe them)
 *        CR ASSET_CASH                    (money out)
 *   3. Emits `payout.settled`
 *
 * In v1 the actual money movement is faked (no bank API). The ledger stays
 * consistent — this is a real double-entry posting.
 */
@Service
class PayoutService(
    private val posting: LedgerPostingService,
    private val events: LedgerEventPublisher,
) {
    private val log = LoggerFactory.getLogger(javaClass)

    data class PayoutResult(
        val payoutId: String,
        val organizerId: String,
        val netCents: Long,
        val journalEntryId: String,
    )

    /**
     * Settle a single organizer for a given period.
     *
     * @return null if the organizer's net payable balance is zero or negative
     *         (nothing to pay out).
     */
    @Transactional
    fun settleOrganizer(
        organizerId: String,
        periodStart: Instant,
        periodEnd: Instant,
    ): PayoutResult? {
        // ─── 1. Compute what we owe ─────────────────────────
        // In a multi-tenant schema, we'd filter by organizer_id. For v1 the
        // ledger has one organizer's worth of postings per period, so we
        // look at the account balance directly.
        //
        // TODO(v2): scope LIABILITY_ORGANIZER_PAYABLE per organizer via a
        // sub-ledger. For now assume single-tenant.
        val netCents = posting.balanceOf("LIABILITY_ORGANIZER_PAYABLE", periodEnd)

        if (netCents <= 0) {
            log.info("payout skipped: organizer={} has no payable balance (net={})",
                organizerId, netCents)
            return null
        }

        // ─── 2. Post the settlement entry ───────────────────
        val payoutId = newId()
        val entry = JournalEntry(
            id = posting.newId(),
            reference = "PAYOUT-$payoutId",
            description = "Payout for organizer $organizerId ($periodStart .. $periodEnd)",
            entryType = JournalEntryType.PAYOUT,
            postedAt = Instant.now(),
            idempotencyKey = "payout:$organizerId:${periodEnd.epochSecond}",
        )
        entry.addLine(
            JournalLine.debit(
                posting.newId(), "LIABILITY_ORGANIZER_PAYABLE", netCents,
                "Settle payable",
            )
        )
        entry.addLine(
            JournalLine.credit(
                posting.newId(), "ASSET_CASH", netCents,
                "Cash out to organizer",
            )
        )
        val saved = posting.post(entry)

        // ─── 3. Emit payout.settled ─────────────────────────
        // Gross/fees/tax here are approximations for v1 — v2 should query the
        // underlying charge entries to compute exact splits.
        events.publishPayoutSettled(
            payoutId = payoutId,
            organizerId = organizerId,
            periodStart = periodStart,
            periodEnd = periodEnd,
            grossCents = netCents,   // simplification: net == gross in v1 payout
            feesCents = 0,
            taxCents = 0,
            netCents = netCents,
            currency = "USD",
            journalEntryId = saved.id,
        )

        log.info("payout settled: organizer={} net={} entry={}",
            organizerId, netCents, saved.id)

        return PayoutResult(
            payoutId = payoutId,
            organizerId = organizerId,
            netCents = netCents,
            journalEntryId = saved.id,
        )
    }

    /**
     * Settle all organizers for the previous week.
     * Called by SettlementScheduler.
     */
    @Transactional
    fun settleWeekly(now: Instant = Instant.now()): List<PayoutResult> {
        val periodEnd = now
        val periodStart = now.minus(7, ChronoUnit.DAYS)

        // In v1 we have one implicit organizer. v2 will iterate over the list.
        val organizers = listOf("default")
        return organizers.mapNotNull { org ->
            settleOrganizer(org, periodStart, periodEnd)
        }
    }

    private fun newId(): String {
        val alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
        val ts = System.currentTimeMillis()
        val sb = StringBuilder(26)
        var v = ts
        repeat(10) {
            sb.insert(0, alphabet[(v and 31).toInt()])
            v = v shr 5
        }
        val rand = UUID.randomUUID().toString().replace("-", "").uppercase()
            .filter { it in alphabet }
        sb.append(rand.take(16).padEnd(16, '0'))
        return sb.toString().take(26)
    }
}