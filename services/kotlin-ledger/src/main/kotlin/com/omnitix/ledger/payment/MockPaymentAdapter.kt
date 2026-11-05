package com.omnitix.ledger.payment

import org.slf4j.LoggerFactory
import java.util.concurrent.ConcurrentHashMap

/**
 * MockPaymentAdapter simulates a payment provider.
 *
 * Configurable via:
 *   ledger.payment.mock.failure-rate (0.0–1.0)
 *   ledger.payment.mock.latency-ms   (simulated round-trip)
 *
 * State (charges, refunds) is kept in memory. Not persistent — restarting
 * the service wipes it. That's fine for local dev.
 *
 * Not annotated as @Component — PaymentAdapterConfig constructs this
 * explicitly, passing in the resolved config values.
 */
class MockPaymentAdapter(
    private val failureRate: Double,
    private val latencyMs: Long,
) : PaymentAdapter {

    override val name: String = "mock"

    private val log = LoggerFactory.getLogger(javaClass)
    private val idempotencyCache = ConcurrentHashMap<String, Any>()
    private val charges = ConcurrentHashMap<String, ChargeResult.Succeeded>()

    override fun charge(request: ChargeRequest): ChargeResult {
        // ─── Idempotency ────────────────────────────────────
        idempotencyCache[request.idempotencyKey]?.let { cached ->
            log.debug("mock charge idempotent replay: key={}", request.idempotencyKey)
            return cached as ChargeResult
        }

        // ─── Simulate latency ───────────────────────────────
        if (latencyMs > 0) Thread.sleep(latencyMs)

        // ─── Simulate failure ───────────────────────────────
        val result: ChargeResult = if (shouldFail()) {
            ChargeResult.Failed(
                paymentId = request.paymentId,
                failureCode = "card_declined",
                failureMessage = "Simulated card decline (failure-rate=$failureRate)",
            )
        } else {
            val providerRef = "mock_ch_${System.currentTimeMillis()}_${request.paymentId.takeLast(6)}"
            ChargeResult.Succeeded(
                paymentId = request.paymentId,
                providerRef = providerRef,
                chargedAmountCents = request.amountCents,
                currency = request.currency,
            ).also { charges[request.paymentId] = it }
        }

        idempotencyCache[request.idempotencyKey] = result
        log.info(
            "mock charge: paymentId={} amount={} {} → {}",
            request.paymentId, request.amountCents, request.currency,
            if (result is ChargeResult.Succeeded) "SUCCESS (${result.providerRef})" else "FAILED (${(result as ChargeResult.Failed).failureCode})"
        )
        return result
    }

    override fun refund(request: RefundRequest): RefundResult {
        idempotencyCache[request.idempotencyKey]?.let { cached ->
            log.debug("mock refund idempotent replay: key={}", request.idempotencyKey)
            return cached as RefundResult
        }

        if (latencyMs > 0) Thread.sleep(latencyMs)

        val original = charges[request.paymentId]
            ?: return RefundResult.Failed(
                refundId = request.refundId,
                failureCode = "payment_not_found",
                failureMessage = "No such payment: ${request.paymentId}",
            )

        if (request.amountCents > original.chargedAmountCents) {
            return RefundResult.Failed(
                refundId = request.refundId,
                failureCode = "amount_exceeds_original",
                failureMessage = "Refund amount ${request.amountCents} > original ${original.chargedAmountCents}",
            )
        }

        val providerRef = "mock_re_${System.currentTimeMillis()}_${request.refundId.takeLast(6)}"
        val result = RefundResult.Succeeded(
            refundId = request.refundId,
            providerRef = providerRef,
            refundedAmountCents = request.amountCents,
            currency = original.currency,
        )
        idempotencyCache[request.idempotencyKey] = result
        log.info(
            "mock refund: refundId={} paymentId={} amount={} → SUCCESS (${providerRef})",
            request.refundId, request.paymentId, request.amountCents
        )
        return result
    }

    override fun lookup(paymentId: String): PaymentLookupResult? {
        val charge = charges[paymentId] ?: return PaymentLookupResult.NotFound
        return PaymentLookupResult.Found(
            paymentId = charge.paymentId,
            providerRef = charge.providerRef,
            amountCents = charge.chargedAmountCents,
            currency = charge.currency,
            status = "succeeded",
        )
    }

    private fun shouldFail(): Boolean {
        if (failureRate <= 0.0) return false
        if (failureRate >= 1.0) return true
        return Math.random() < failureRate
    }
}