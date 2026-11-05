package com.omnitix.ledger.persistence

import com.omnitix.ledger.domain.Account
import com.omnitix.ledger.domain.JournalEntry
import org.springframework.data.jpa.repository.JpaRepository
import org.springframework.data.jpa.repository.Query
import org.springframework.data.repository.query.Param
import org.springframework.stereotype.Repository
import java.time.Instant

@Repository
interface AccountRepository : JpaRepository<Account, String> {
    fun findByAccountType(accountType: String): List<Account>
}

@Repository
interface JournalEntryRepository : JpaRepository<JournalEntry, String> {

    fun findByReference(reference: String): List<JournalEntry>

    // NEW: idempotency lookup. Returns the single entry that carries this
    // key, or null if the key has never been used. Called on every post()
    // that sets an idempotency key, so the column is indexed.
    fun findByIdempotencyKey(idempotencyKey: String): JournalEntry?

    @Query(
        """
        SELECT e FROM JournalEntry e
        WHERE e.postedAt >= :from AND e.postedAt <= :to
        ORDER BY e.postedAt DESC
        """,
        countQuery = """
        SELECT COUNT(e) FROM JournalEntry e
        WHERE e.postedAt >= :from AND e.postedAt <= :to
        """
    )
    fun findInDateRange(@Param("from") from: Instant, @Param("to") to: Instant): List<JournalEntry>
}