package com.omnitix.ledger.payment

/**
 * Stripe adapter stub.
 *
 * Deliberately not implemented in v1. Constructing this throws — it exists
 * only to:
 *   - document the seam
 *   - fail loudly if someone flips `ledger.payment.adapter=stripe`
 *   - give the v2 implementer a file to fill in
 *
 * The plan locks in "mocked payment in v1" so a silent fallback (e.g.
 * returning mock results) would be actively wrong. Throwing is honest.
 */
class StripePaymentAdapter(
    private val apiKey: String,
) : PaymentAdapter {

    init {
        require(apiKey.isNotEmpty()) {
            "StripePaymentAdapter requires an API key. " +
            "Set STRIPE_API_KEY env var, or use PAYMENT_ADAPTER=mock."
        }
    }

    override val name: String = "stripe"

    override fun charge(request: ChargeRequest): ChargeResult =
        throw UnsupportedOperationException(
            "StripePaymentAdapter is a v2 stub. " +
            "Set ledger.payment.adapter=mock to run locally."
        )

    override fun refund(request: RefundRequest): RefundResult =
        throw UnsupportedOperationException(
            "StripePaymentAdapter is a v2 stub."
        )

    override fun lookup(paymentId: String): PaymentLookupResult? =
        throw UnsupportedOperationException(
            "StripePaymentAdapter is a v2 stub."
        )
}