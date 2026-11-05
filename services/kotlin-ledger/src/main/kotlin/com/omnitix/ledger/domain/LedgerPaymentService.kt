package com.omnitix.ledger.domain

import com.omnitix.ledger.kafka.LedgerEventPublisher
import com.omnitix.ledger.payment.*
import org.slf4j.LoggerFactory
import org.springframework.stereotype.Service
import org.springframework.transaction.annotation.Transactional
import java.time.Instant

/**
 * LedgerPaymentService orchestrates a full payment lifecycle:
 *   1. Calls the payment adapter (mock or stripe)
 *   2. Posts a balanced double-entry journal entry (idempotent by key)
 *   3. Emits payment.completed / payment.failed to Kafka
 *   4. Returns a structured result
 */
@Service
class LedgerPaymentService(
    private val adapter: PaymentAdapter,
    private val posting: LedgerPostingService,
    private val events: LedgerEventPublisher,
) {
    private val log = LoggerFactory.getLogger(javaClass)

    @Transactional
    fun charge(request: ChargeCommand): ChargeOutcome {
        val adapterRequest = ChargeRequest(
            paymentId = request.paymentId,
            orderId = request.orderId,
            organizerId = request.organizerId,
            buyerUserId = request.buyerUserId,
            buyerEmail = request.buyerEmail,
            amountCents = request.amountCents,
            currency = request.currency,
            paymentMethodToken = request.paymentMethodToken,
            idempotencyKey = request.idempotencyKey,
        )

        val adapterResult = adapter.charge(adapterRequest)

        return when (adapterResult) {
            is ChargeResult.Succeeded -> {
                val entry = JournalEntry(
                    id = posting.newId(),
                    reference = "ORDER-${request.orderId}",
                    description = "Ticket charge for ${request.buyerEmail}",
                    entryType = JournalEntryType.PAYMENT_CHARGE,
                    postedAt = Instant.now(),
                    idempotencyKey = request.idempotencyKey,
                )
                entry.addLine(
                    JournalLine.debit(
                        posting.newId(), "ASSET_CASH",
                        adapterResult.chargedAmountCents,
                        "Charge ${adapterResult.providerRef}",
                    )
                )
                entry.addLine(
                    JournalLine.credit(
                        posting.newId(), "REVENUE_TICKETS",
                        request.ticketRevenueCents,
                        "Face value",
                    )
                )
                if (request.feeRevenueCents > 0) {
                    entry.addLine(
                        JournalLine.credit(
                            posting.newId(), "REVENUE_FEES",
                            request.feeRevenueCents,
                            "Service fees",
                        )
                    )
                }
                if (request.taxPayableCents > 0) {
                    entry.addLine(
                        JournalLine.credit(
                            posting.newId(), "LIABILITY_TAX_PAYABLE",
                            request.taxPayableCents,
                            "Sales tax",
                        )
                    )
                }
                val saved = posting.post(entry)

                log.info(
                    "charge succeeded: paymentId={} orderId={} amount={} ref={} journal={}",
                    request.paymentId, request.orderId,
                    adapterResult.chargedAmountCents, adapterResult.providerRef, saved.id,
                )

                // ─── Emit payment.completed ─────────────────
                events.publishPaymentCompleted(
                    paymentId = adapterResult.paymentId,
                    orderId = request.orderId,
                    userId = request.buyerUserId,
                    amountCents = adapterResult.chargedAmountCents,
                    currency = adapterResult.currency,
                    provider = adapter.name,
                    providerRef = adapterResult.providerRef,
                    journalEntryId = saved.id,
                    correlationId = request.correlationId,
                )

                ChargeOutcome.Succeeded(
                    paymentId = adapterResult.paymentId,
                    providerRef = adapterResult.providerRef,
                    chargedAmountCents = adapterResult.chargedAmountCents,
                    currency = adapterResult.currency,
                    journalEntryId = saved.id,
                )
            }

            is ChargeResult.Failed -> {
                log.warn(
                    "charge failed: paymentId={} orderId={} code={}",
                    request.paymentId, request.orderId, adapterResult.failureCode,
                )

                events.publishPaymentFailed(
                    orderId = request.orderId,
                    userId = request.buyerUserId,
                    amountCents = request.amountCents,
                    currency = request.currency,
                    provider = adapter.name,
                    failureCode = adapterResult.failureCode,
                    failureMessage = adapterResult.failureMessage,
                    correlationId = request.correlationId,
                )

                ChargeOutcome.Failed(
                    paymentId = adapterResult.paymentId,
                    failureCode = adapterResult.failureCode,
                    failureMessage = adapterResult.failureMessage,
                    providerRef = adapterResult.providerRef,
                )
            }
        }
    }

    /**
     * Refund a prior charge, then post a balanced reversal entry.
     *
     * For a partial refund of $25 on a $100 ticket with:
     *   - $90 tickets, $10 fees, $0 tax
     * we reverse proportionally:
     *   DR REVENUE_TICKETS        22.50
     *   DR REVENUE_FEES            2.50
     *   CR ASSET_CASH             25.00
     *
     * If the original charge can't be found, we fall back to the v1
     * behaviour (all revenue_tickets) — clearly logged as a warn.
     */
    @Transactional
    fun refund(request: RefundCommand): RefundOutcome {
        val adapterRequest = RefundRequest(
            refundId = request.refundId,
            paymentId = request.paymentId,
            providerRef = request.providerRef,
            amountCents = request.amountCents,
            reason = request.reason,
            idempotencyKey = request.idempotencyKey,
        )

        val adapterResult = adapter.refund(adapterRequest)

        return when (adapterResult) {
            is RefundResult.Succeeded -> {
                val entry = JournalEntry(
                    id = posting.newId(),
                    reference = "REFUND-${request.refundId}",
                    description = "Refund for payment ${request.paymentId}",
                    entryType = JournalEntryType.REFUND,
                    postedAt = Instant.now(),
                    idempotencyKey = request.idempotencyKey,
                )

                // Reverse the exact account mix used on the original charge,
                // proportional to the refund fraction.
                val original = posting.findChargeForOrder(request.orderId)
                val refundAmount = adapterResult.refundedAmountCents

                if (original != null) {
                    val originalTotal = original.lines.sumOf { it.debitCents } // == sum credits
                    val fraction = if (originalTotal > 0) {
                        refundAmount.toDouble() / originalTotal
                    } else 1.0

                    // Debit each credit-side account by its proportional share
                    var allocated = 0L
                    val creditLines = original.lines.filter { it.creditCents > 0 }
                    creditLines.forEachIndexed { idx, line ->
                        val isLast = idx == creditLines.lastIndex
                        val share = if (isLast) {
                            refundAmount - allocated
                        } else {
                            (line.creditCents * fraction).toLong()
                        }
                        if (share <= 0) return@forEachIndexed
                        allocated += share
                        entry.addLine(
                            JournalLine.debit(
                                posting.newId(), line.accountCode, share,
                                "Refund reversal (${line.memo ?: line.accountCode})",
                            )
                        )
                    }
                } else {
                    log.warn(
                        "refund: no original charge entry found for order={}; " +
                        "falling back to REVENUE_TICKETS reversal",
                        request.orderId,
                    )
                    entry.addLine(
                        JournalLine.debit(
                            posting.newId(), "REVENUE_TICKETS", refundAmount,
                            "Refund ${adapterResult.providerRef}",
                        )
                    )
                }

                entry.addLine(
                    JournalLine.credit(
                        posting.newId(), "ASSET_CASH", refundAmount,
                        "Refund to buyer",
                    )
                )

                val saved = posting.post(entry)

                log.info(
                    "refund succeeded: refundId={} paymentId={} amount={} ref={} journal={}",
                    request.refundId, request.paymentId, refundAmount,
                    adapterResult.providerRef, saved.id,
                )

                events.publishRefundIssued(
                    refundId = adapterResult.refundId,
                    paymentId = request.paymentId,
                    orderId = request.orderId,
                    amountCents = refundAmount,
                    currency = adapterResult.currency,
                    reason = request.reason,
                    providerRef = adapterResult.providerRef,
                    affectedTicketIds = request.affectedTicketIds,
                    correlationId = request.correlationId,
                )

                RefundOutcome.Succeeded(
                    refundId = adapterResult.refundId,
                    providerRef = adapterResult.providerRef,
                    refundedAmountCents = refundAmount,
                    journalEntryId = saved.id,
                )
            }

            is RefundResult.Failed -> {
                log.warn(
                    "refund failed: refundId={} code={}",
                    request.refundId, adapterResult.failureCode,
                )
                RefundOutcome.Failed(
                    refundId = adapterResult.refundId,
                    failureCode = adapterResult.failureCode,
                    failureMessage = adapterResult.failureMessage,
                )
            }
        }
    }

    fun lookupPayment(paymentId: String): PaymentLookupResult? = adapter.lookup(paymentId)

    fun balanceOf(accountCode: String): Long = posting.balanceOf(accountCode)
}

// ─── Command/Outcome types ──────────────────────────────────

data class ChargeCommand(
    val paymentId: String,
    val orderId: String,
    val organizerId: String,
    val buyerUserId: String,
    val buyerEmail: String,
    val amountCents: Long,
    val currency: String,
    val paymentMethodToken: String,
    val idempotencyKey: String,
    val ticketRevenueCents: Long,
    val feeRevenueCents: Long,
    val taxPayableCents: Long,
    val correlationId: String? = null,
)

sealed interface ChargeOutcome {
    data class Succeeded(
        val paymentId: String,
        val providerRef: String,
        val chargedAmountCents: Long,
        val currency: String,
        val journalEntryId: String,
    ) : ChargeOutcome

    data class Failed(
        val paymentId: String,
        val failureCode: String,
        val failureMessage: String,
        val providerRef: String?,
    ) : ChargeOutcome
}

data class RefundCommand(
    val refundId: String,
    val paymentId: String,
    val orderId: String,
    val providerRef: String,
    val amountCents: Long,
    val reason: String,
    val idempotencyKey: String,
    val affectedTicketIds: List<String> = emptyList(),
    val correlationId: String? = null,
)

sealed interface RefundOutcome {
    data class Succeeded(
        val refundId: String,
        val providerRef: String,
        val refundedAmountCents: Long,
        val journalEntryId: String,
    ) : RefundOutcome

    data class Failed(
        val refundId: String,
        val failureCode: String,
        val failureMessage: String,
    ) : RefundOutcome
}