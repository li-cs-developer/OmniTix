package com.omnitix.ledger

import com.omnitix.ledger.persistence.JournalEntryRepository
import org.junit.jupiter.api.BeforeEach
import org.springframework.beans.factory.annotation.Autowired
import org.springframework.boot.test.context.SpringBootTest
import org.springframework.test.context.ActiveProfiles
import org.springframework.transaction.annotation.Transactional

/**
 * Shared base for every `@SpringBootTest` in this module.
 *
 * The `properties` overrides are the important bit:
 *   - `grpc.server.enabled=false`       disables the gRPC listener
 *   - `ledger.http.enabled=false`       disables the HTTP gateway listener
 *   - `spring.task.scheduling.enabled=false` disables the reconciliation cron
 *
 * Without all three, loading a second Spring context in the same JVM
 * (which happens whenever two test classes have different
 * `@MockitoBean` signatures — e.g. one mocks `LedgerEventPublisher`
 * and the other doesn't) will try to bind sockets that the first
 * context already holds, and fail with `BindException`.
 *
 * The `@BeforeEach` deletes every journal entry so balance assertions
 * (`posting.balanceOf(...)`) start from zero — cumulative state from a
 * prior test method or class otherwise leaks in and produces false
 * failures like `expected:<10000> but was:<20000>`.
 */
@SpringBootTest(properties = [
    "grpc.server.enabled=false",
    "ledger.http.enabled=false",
    "spring.task.scheduling.enabled=false",
])
@ActiveProfiles("test")
@Transactional
abstract class IntegrationTestBase {

    @Autowired
    protected lateinit var journalEntries: JournalEntryRepository

    @BeforeEach
    fun cleanJournalEntries() {
        journalEntries.deleteAll()
    }
}