package com.omnitix.ledger.domain

import org.slf4j.LoggerFactory
import org.springframework.scheduling.annotation.Scheduled
import org.springframework.stereotype.Component

/**
 * Daily and weekly settlement crons.
 *
 * Enable by adding @EnableScheduling to LedgerApplication (or a
 * dedicated @Configuration class). Times are UTC.
 *
 *   - 00:00 UTC daily   — roll forward any settlement state (no-op in v1)
 *   - 00:05 UTC Mondays — weekly payout for the previous 7 days
 */
@Component
class SettlementScheduler(
    private val payoutService: PayoutService,
) {
    private val log = LoggerFactory.getLogger(javaClass)

    @Scheduled(cron = "0 0 0 * * *", zone = "UTC")
    fun dailySettlementRollover() {
        log.info("daily settlement tick")
        // v1: no-op. v2 will:
        //   - materialize balances
        //   - snapshot P&L
        //   - age receivables
    }

    @Scheduled(cron = "0 5 0 * * MON", zone = "UTC")
    fun weeklyPayout() {
        log.info("weekly payout starting")
        try {
            val results = payoutService.settleWeekly()
            log.info("weekly payout complete: {} organizers settled", results.size)
        } catch (e: Exception) {
            log.error("weekly payout failed", e)
        }
    }
}