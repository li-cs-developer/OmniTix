package com.omnitix.ledger.payment

import org.junit.jupiter.api.Assertions.*
import org.junit.jupiter.api.Test

class MockPaymentAdapterTest {

    private fun newAdapter(failureRate: Double = 0.0): MockPaymentAdapter =
        MockPaymentAdapter(failureRate = failureRate, latencyMs = 0)

    private fun chargeRequest(
        paymentId: String = "01PAY",
        amountCents: Long = 10_000,
        idempotencyKey: String = "idem-${System.currentTimeMillis()}",
    ) = ChargeRequest(
        paymentId = paymentId,
        orderId = "01ORDER",
        organizerId = "01ORG",
        buyerUserId = "01USER",
        buyerEmail = "buyer@example.test",
        amountCents = amountCents,
        paymentMethodToken = "tok_test_visa",
        idempotencyKey = idempotencyKey,
    )

    @Test
    fun `charge succeeds by default`() {
        val adapter = newAdapter()
        val result = adapter.charge(chargeRequest())
        assertTrue(result is ChargeResult.Succeeded)
        assertEquals(10_000, (result as ChargeResult.Succeeded).chargedAmountCents)
        assertTrue(result.providerRef.startsWith("mock_ch_"))
    }

    @Test
    fun `charge fails when failure-rate is 1_0`() {
        val adapter = newAdapter(failureRate = 1.0)
        val result = adapter.charge(chargeRequest())
        assertTrue(result is ChargeResult.Failed)
        assertEquals("card_declined", (result as ChargeResult.Failed).failureCode)
    }

    @Test
    fun `charge honors idempotency key`() {
        val adapter = newAdapter()
        val key = "idem-test-001"
        val first = adapter.charge(chargeRequest(idempotencyKey = key))
        val second = adapter.charge(chargeRequest(idempotencyKey = key))
        assertTrue(first is ChargeResult.Succeeded)
        assertTrue(second is ChargeResult.Succeeded)
        // Same provider ref → same underlying charge was returned
        assertEquals(
            (first as ChargeResult.Succeeded).providerRef,
            (second as ChargeResult.Succeeded).providerRef,
        )
    }

    @Test
    fun `refund succeeds on a valid charge`() {
        val adapter = newAdapter()
        val charge = adapter.charge(chargeRequest()) as ChargeResult.Succeeded

        val refund = adapter.refund(
            RefundRequest(
                refundId = "01REFUND",
                paymentId = charge.paymentId,
                providerRef = charge.providerRef,
                amountCents = 2_500,
                reason = "Customer request",
                idempotencyKey = "idem-refund-001",
            )
        )
        assertTrue(refund is RefundResult.Succeeded)
        assertEquals(2_500, (refund as RefundResult.Succeeded).refundedAmountCents)
    }

    @Test
    fun `refund fails when amount exceeds original`() {
        val adapter = newAdapter()
        val charge = adapter.charge(chargeRequest(amountCents = 1_000)) as ChargeResult.Succeeded

        val refund = adapter.refund(
            RefundRequest(
                refundId = "01REFUND",
                paymentId = charge.paymentId,
                providerRef = charge.providerRef,
                amountCents = 5_000,  // > 1_000
                reason = "Over-refund attempt",
                idempotencyKey = "idem-refund-002",
            )
        )
        assertTrue(refund is RefundResult.Failed)
        assertEquals("amount_exceeds_original", (refund as RefundResult.Failed).failureCode)
    }

    @Test
    fun `refund fails for unknown payment`() {
        val adapter = newAdapter()
        val refund = adapter.refund(
            RefundRequest(
                refundId = "01REFUND",
                paymentId = "01GHOST",
                providerRef = "mock_ch_nonexistent",
                amountCents = 500,
                reason = "Ghost refund",
                idempotencyKey = "idem-refund-003",
            )
        )
        assertTrue(refund is RefundResult.Failed)
        assertEquals("payment_not_found", (refund as RefundResult.Failed).failureCode)
    }

    @Test
    fun `lookup finds a charge`() {
        val adapter = newAdapter()
        val charge = adapter.charge(chargeRequest()) as ChargeResult.Succeeded
        val found = adapter.lookup(charge.paymentId)
        assertTrue(found is PaymentLookupResult.Found)
        assertEquals(10_000, (found as PaymentLookupResult.Found).amountCents)
    }

    @Test
    fun `lookup returns NotFound for unknown payment`() {
        val adapter = newAdapter()
        val found = adapter.lookup("01GHOST")
        assertTrue(found is PaymentLookupResult.NotFound)
    }
}