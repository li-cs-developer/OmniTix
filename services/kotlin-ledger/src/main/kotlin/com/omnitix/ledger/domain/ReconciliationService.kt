package com.omnitix.ledger.domain

import com.omnitix.ledger.persistence.JournalEntryRepository
import org.slf4j.LoggerFactory
import org.springframework.scheduling.annotation.Scheduled
import org.springframework.stereotype.Service
import org.springframework.transaction.annotation.Transactional
import java.time.Instant
import java.time.temporal.ChronoUnit

/**
 * ReconciliationService verifies the double-entry invariant globally.
 *
 * Per-entry balancing is already enforced at write time (JournalEntry.validateBalanced
 * + DB CHECK constraint). This service catches the far-rarer case where:
 *   - a bug bypassed the invariant
 *   - a migration or manual SQL altered lines
 *   - a partial crash left a half-written entry
 *
 * Runs nightly. On mismatch, logs at ERROR and returns a non-zero count so
 * Kubernetes alerting can pick it up (eventually — via the LogsMetric if we
 * add one).
 */
@Service
class ReconciliationService(
    private val entries: JournalEntryRepository,
) {
    private val log = LoggerFactory.getLogger(javaClass)

    data class ReconciliationResult(
        val entriesChecked: Long,
        val debitsTotal: Long,
        val creditsTotal: Long,
        val mismatchCount: Long,
    )

    /**
     * Check every entry posted in the last 24 hours.
     */
    @Transactional(readOnly = true)
    fun reconcileLastDay(now: Instant = Instant.now()): ReconciliationResult {
        val from = now.minus(1, ChronoUnit.DAYS)
        return reconcileRange(from, now)
    }

    /**
     * Check every entry in a date range.
     */
    @Transactional(readOnly = true)
    fun reconcileRange(from: Instant, to: Instant): ReconciliationResult {
        val list = entries.findInDateRange(from, to)

        var totalDebits = 0L
        var totalCredits = 0L
        var mismatches = 0L

        for (entry in list) {
            val d = entry.lines.sumOf { it.debitCents }
            val c = entry.lines.sumOf { it.creditCents }
            totalDebits += d
            totalCredits += c
            if (d != c) {
                mismatches++
                log.error(
                    "UNBALANCED JOURNAL ENTRY: id={} ref={} debits={} credits={} diff={}",
                    entry.id, entry.reference, d, c, d - c,
                )
            }
        }

        val result = ReconciliationResult(
            entriesChecked = list.size.toLong(),
            debitsTotal = totalDebits,
            creditsTotal = totalCredits,
            mismatchCount = mismatches,
        )

        if (mismatches == 0L) {
            log.info(
                "reconciliation OK: {} entries, debits={} credits={}",
                result.entriesChecked, result.debitsTotal, result.creditsTotal,
            )
        } else {
            log.error(
                "RECONCILIATION FAILED: {} unbalanced entries out of {} checked",
                result.mismatchCount, result.entriesChecked,
            )
        }

        return result
    }

    @Scheduled(cron = "0 30 0 * * *", zone = "UTC")
    fun nightlyReconciliation() {
        log.info("nightly reconciliation starting")
        try {
            val result = reconcileLastDay()
            if (result.mismatchCount > 0) {
                log.error(
                    "NIGHTLY RECONCILIATION MISMATCH: {} entries in 24h window are unbalanced",
                    result.mismatchCount,
                )
            }
        } catch (e: Exception) {
            log.error("nightly reconciliation failed", e)
        }
    }
}