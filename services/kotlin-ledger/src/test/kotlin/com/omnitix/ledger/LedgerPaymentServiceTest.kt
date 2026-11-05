package com.omnitix.ledger

import com.omnitix.ledger.domain.*
import com.omnitix.ledger.kafka.LedgerEventPublisher
import com.omnitix.ledger.payment.ChargeResult
import com.omnitix.ledger.payment.MockPaymentAdapter
import org.junit.jupiter.api.Assertions.*
import org.junit.jupiter.api.Test
import org.springframework.beans.factory.annotation.Autowired
import org.springframework.test.context.bean.override.mockito.MockitoBean

class LedgerPaymentServiceTest : IntegrationTestBase() {

    @Autowired lateinit var posting: LedgerPostingService
    @Autowired lateinit var paymentService: LedgerPaymentService

    @MockitoBean
    lateinit var events: LedgerEventPublisher

    @Test
    fun `charge posts entry with fee and tax splits`() {
        val adapter = MockPaymentAdapter(failureRate = 0.0, latencyMs = 0)

        val cmd = ChargeCommand(
            paymentId = "01PAY",
            orderId = "01ORDER",
            organizerId = "",
            buyerUserId = "01USER",
            buyerEmail = "buyer@example.test",
            amountCents = 10_000,
            currency = "USD",
            paymentMethodToken = "tok_test",
            idempotencyKey = "idem-charge-split",
            ticketRevenueCents = 9_000,
            feeRevenueCents = 500,
            taxPayableCents = 500,
        )

        val adapterResult = adapter.charge(
            com.omnitix.ledger.payment.ChargeRequest(
                paymentId = cmd.paymentId,
                orderId = cmd.orderId,
                organizerId = cmd.organizerId,
                buyerUserId = cmd.buyerUserId,
                buyerEmail = cmd.buyerEmail,
                amountCents = cmd.amountCents,
                currency = cmd.currency,
                paymentMethodToken = cmd.paymentMethodToken,
                idempotencyKey = cmd.idempotencyKey,
            )
        )
        assertTrue(adapterResult is ChargeResult.Succeeded)

        val outcome = paymentService.charge(cmd)
        assertTrue(outcome is ChargeOutcome.Succeeded)

        assertEquals(10_000, posting.balanceOf("ASSET_CASH"))
        assertEquals(9_000, posting.balanceOf("REVENUE_TICKETS"))
        assertEquals(500, posting.balanceOf("REVENUE_FEES"))
        assertEquals(500, posting.balanceOf("LIABILITY_TAX_PAYABLE"))
    }

    @Test
    fun `charge is idempotent by idempotency key`() {
        val cmd = ChargeCommand(
            paymentId = "01PAY",
            orderId = "01ORDER",
            organizerId = "",
            buyerUserId = "01USER",
            buyerEmail = "buyer@example.test",
            amountCents = 10_000,
            currency = "USD",
            paymentMethodToken = "tok_test",
            idempotencyKey = "idem-idempotent-charge",
            ticketRevenueCents = 10_000,
            feeRevenueCents = 0,
            taxPayableCents = 0,
        )
        val first = paymentService.charge(cmd)
        assertTrue(first is ChargeOutcome.Succeeded)

        val second = paymentService.charge(cmd)
        assertTrue(second is ChargeOutcome.Succeeded)
        assertEquals(
            (first as ChargeOutcome.Succeeded).journalEntryId,
            (second as ChargeOutcome.Succeeded).journalEntryId,
            "idempotent replay should return the same journal entry",
        )
    }

    @Test
    fun `refund reverses the exact account mix of the original charge`() {
        val charge = ChargeCommand(
            paymentId = "01PAY",
            orderId = "01ORDER-REFUND",
            organizerId = "",
            buyerUserId = "01USER",
            buyerEmail = "buyer@example.test",
            amountCents = 10_000,
            currency = "USD",
            paymentMethodToken = "tok_test",
            idempotencyKey = "idem-charge-refund",
            ticketRevenueCents = 9_000,
            feeRevenueCents = 500,
            taxPayableCents = 500,
        )
        paymentService.charge(charge)

        val refund = RefundCommand(
            refundId = "01REFUND",
            paymentId = "01PAY",
            orderId = "01ORDER-REFUND",
            providerRef = "",
            amountCents = 10_000,
            reason = "test",
            idempotencyKey = "idem-refund-1",
        )
        val outcome = paymentService.refund(refund)
        assertTrue(outcome is RefundOutcome.Succeeded)

        assertEquals(0, posting.balanceOf("ASSET_CASH"))
        assertEquals(0, posting.balanceOf("REVENUE_TICKETS"))
        assertEquals(0, posting.balanceOf("REVENUE_FEES"))
        assertEquals(0, posting.balanceOf("LIABILITY_TAX_PAYABLE"))
    }
}