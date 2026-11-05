package com.omnitix.ledger.domain

import jakarta.persistence.*
import java.time.Instant

/**
 * A named account in the chart of accounts.
 *
 * Account codes are string constants so we can add new accounts without
 * migrations (e.g. `REVENUE_TICKETS_VIP`).
 */
@Entity
@Table(name = "accounts", schema = "ledger")
class Account(
    @Id
    @Column(name = "code", length = 64, nullable = false)
    var code: String,

    @Column(name = "name", nullable = false)
    var name: String,

    @Enumerated(EnumType.STRING)
    @Column(name = "account_type", nullable = false, length = 16)
    var accountType: AccountType,

    @Column(name = "currency", nullable = false, length = 3)
    var currency: String = "USD",

    @Column(name = "is_system", nullable = false)
    var isSystem: Boolean = false,

    @Column(name = "created_at", nullable = false, updatable = false)
    var createdAt: Instant = Instant.now(),
) {
    override fun equals(other: Any?): Boolean =
        this === other || (other is Account && other.code == code)

    override fun hashCode(): Int = code.hashCode()

    override fun toString(): String = "Account($code, $name, $accountType)"
}