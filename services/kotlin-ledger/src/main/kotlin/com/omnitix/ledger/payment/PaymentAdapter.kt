package com.omnitix.ledger.payment

/**
 * PaymentAdapter is the seam between OmniTix and whatever payment provider
 * we're using. In v1 we ship MockPaymentAdapter; in v2 we ship StripeAdapter.
 *
 * The adapter is a thin provider wrapper:
 *   - It knows how to talk to the provider (HTTP, SDK, whatever).
 *   - It does NOT know about the ledger, journal entries, or Kafka.
 *   - It returns structured results, never throws for expected failures.
 */
interface PaymentAdapter {
    val name: String  // "mock" or "stripe"

    /**
     * Charge a card (or equivalent payment method).
     *
     * Returns a successful charge or a structured failure — never throws
     * for provider-level errors. Throwing means infrastructure broke
     * (network timeout, credentials missing, etc.).
     */
    fun charge(request: ChargeRequest): ChargeResult

    /**
     * Refund a previously successful charge, in whole or in part.
     */
    fun refund(request: RefundRequest): RefundResult

    /**
     * Look up a payment by our internal payment ID (for reconciliation).
     */
    fun lookup(paymentId: String): PaymentLookupResult?
}

// ─── Charge ──────────────────────────────────────────────

data class ChargeRequest(
    /** Our payment ID (ULID). Adapter echoes this back in results. */
    val paymentId: String,
    /** Our order ID (ULID) — passed to provider as metadata. */
    val orderId: String,
    val organizerId: String,
    val buyerUserId: String,
    val buyerEmail: String,
    /** Amount in cents (always positive). */
    val amountCents: Long,
    val currency: String = "USD",
    /** Tokenized payment method from the frontend (Stripe.js token, mock string, etc.) */
    val paymentMethodToken: String,
    /** Idempotency key — retries use the same key, provider deduplicates. */
    val idempotencyKey: String,
)

sealed interface ChargeResult {
    data class Succeeded(
        val paymentId: String,
        val providerRef: String,
        val chargedAmountCents: Long,
        val currency: String,
    ) : ChargeResult

    data class Failed(
        val paymentId: String,
        val failureCode: String,       // stable machine code, e.g. "card_declined"
        val failureMessage: String,    // human-readable
        val providerRef: String? = null,
    ) : ChargeResult
}

// ─── Refund ──────────────────────────────────────────────

data class RefundRequest(
    val refundId: String,
    val paymentId: String,
    val providerRef: String,
    /** Amount to refund in cents. Must be ≤ original charge amount. */
    val amountCents: Long,
    val reason: String,
    val idempotencyKey: String,
)

sealed interface RefundResult {
    data class Succeeded(
        val refundId: String,
        val providerRef: String,
        val refundedAmountCents: Long,
        val currency: String,
    ) : RefundResult

    data class Failed(
        val refundId: String,
        val failureCode: String,
        val failureMessage: String,
        val providerRef: String? = null,
    ) : RefundResult
}

// ─── Lookup ──────────────────────────────────────────────

sealed interface PaymentLookupResult {
    data class Found(
        val paymentId: String,
        val providerRef: String,
        val amountCents: Long,
        val currency: String,
        val status: String,   // provider-specific status string
    ) : PaymentLookupResult

    data object NotFound : PaymentLookupResult
}