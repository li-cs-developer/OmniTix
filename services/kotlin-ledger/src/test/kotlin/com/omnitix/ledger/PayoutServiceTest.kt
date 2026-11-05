package com.omnitix.ledger

import com.omnitix.ledger.domain.*
import com.omnitix.ledger.kafka.LedgerEventPublisher
import org.junit.jupiter.api.Assertions.*
import org.junit.jupiter.api.Test
import org.springframework.beans.factory.annotation.Autowired
import org.springframework.test.context.bean.override.mockito.MockitoBean
import java.time.Instant

class PayoutServiceTest : IntegrationTestBase() {

    @Autowired lateinit var posting: LedgerPostingService
    @Autowired lateinit var payoutService: PayoutService
    @Autowired lateinit var paymentService: LedgerPaymentService

    @MockitoBean
    lateinit var events: LedgerEventPublisher

    @Test
    fun `payout settles payable liability to zero`() {
        val seed = JournalEntry(
            id = posting.newId(),
            reference = "SEED-PAYABLE",
            description = "seed payable for test",
            entryType = JournalEntryType.ADJUSTMENT,
        )
        seed.addLine(JournalLine.debit(posting.newId(), "ASSET_CASH", 100_000, "seed"))
        seed.addLine(JournalLine.credit(posting.newId(), "LIABILITY_ORGANIZER_PAYABLE", 100_000, "seed"))
        posting.post(seed)

        val periodEnd = Instant.now()
        val result = payoutService.settleOrganizer("org1", Instant.EPOCH, periodEnd)

        assertNotNull(result)
        assertEquals(100_000, result!!.netCents)

        assertEquals(0, posting.balanceOf("LIABILITY_ORGANIZER_PAYABLE", periodEnd.plusSeconds(1)))
    }

    @Test
    fun `payout is skipped when payable is zero`() {
        val result = payoutService.settleOrganizer("org1", Instant.EPOCH, Instant.now())
        assertNull(result)
    }
}