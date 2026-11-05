package com.omnitix.ledger

import com.omnitix.ledger.domain.*
import org.junit.jupiter.api.Assertions.*
import org.junit.jupiter.api.Test
import org.springframework.beans.factory.annotation.Autowired
import java.time.Instant

class LedgerPostingServiceTest : IntegrationTestBase() {

    @Autowired lateinit var posting: LedgerPostingService

    @Test
    fun `charge posts a balanced journal entry`() {
        val entryId = posting.newId()
        val entry = JournalEntry(
            id = entryId,
            reference = "TEST-ORDER-001",
            description = "Ticket charge",
            entryType = JournalEntryType.PAYMENT_CHARGE,
            postedAt = Instant.now(),
        )
        entry.addLine(JournalLine.debit(posting.newId(), "ASSET_CASH", 10_000, "Buyer payment"))
        entry.addLine(JournalLine.credit(posting.newId(), "REVENUE_TICKETS", 9_000, "Face value"))
        entry.addLine(JournalLine.credit(posting.newId(), "REVENUE_FEES", 1_000, "Service fee"))

        val saved = posting.post(entry)

        assertEquals(3, saved.lines.size)
        assertEquals(10_000, saved.lines.sumOf { it.debitCents })
        assertEquals(10_000, saved.lines.sumOf { it.creditCents })

        assertEquals(10_000, posting.balanceOf("ASSET_CASH"))
        assertEquals(9_000, posting.balanceOf("REVENUE_TICKETS"))
        assertEquals(1_000, posting.balanceOf("REVENUE_FEES"))
    }

    @Test
    fun `unbalanced entry is rejected`() {
        val entry = JournalEntry(
            id = posting.newId(),
            reference = "TEST-BAD-001",
            description = "Bad entry",
            entryType = JournalEntryType.ADJUSTMENT,
        )
        entry.addLine(JournalLine.debit(posting.newId(), "ASSET_CASH", 5_000, null))
        entry.addLine(JournalLine.credit(posting.newId(), "REVENUE_TICKETS", 4_000, null))

        assertThrows(IllegalArgumentException::class.java) {
            posting.post(entry)
        }
    }

    @Test
    fun `unknown account is rejected`() {
        val entry = JournalEntry(
            id = posting.newId(),
            reference = "TEST-BAD-002",
            description = "Bad account",
            entryType = JournalEntryType.ADJUSTMENT,
        )
        entry.addLine(JournalLine.debit(posting.newId(), "ASSET_CASH", 1_000, null))
        entry.addLine(JournalLine.credit(posting.newId(), "MADE_UP_ACCOUNT", 1_000, null))

        assertThrows(IllegalArgumentException::class.java) {
            posting.post(entry)
        }
    }
}