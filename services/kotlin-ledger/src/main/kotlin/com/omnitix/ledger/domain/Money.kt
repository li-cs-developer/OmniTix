package com.omnitix.ledger.domain

import java.util.Locale

/**
 * Immutable monetary amount, stored as integer cents.
 *
 * Using integers avoids floating-point rounding errors that plague
 * accounting systems. Every amount carries its currency.
 *
 * The primary constructor is private, but the copy() method that data
 * classes normally generate is suppressed by @ConsistentCopyVisibility.
 * Without this annotation, Kotlin 2.x would expose copy() as public,
 * which would let callers bypass the invariant checks in init {}.
 */
@ConsistentCopyVisibility
data class Money private constructor(
    val amountCents: Long,
    val currencyCode: String,
) {
    init {
        require(amountCents >= 0) { "Money cannot be negative; use Debit/Credit to express direction" }
        require(currencyCode.length == 3) { "Currency code must be ISO 4217 (3 letters)" }
    }

    operator fun plus(other: Money): Money {
        requireSameCurrency(other)
        return Money(amountCents + other.amountCents, currencyCode)
    }

    operator fun minus(other: Money): Money {
        requireSameCurrency(other)
        require(amountCents >= other.amountCents) {
            "Subtraction would produce a negative amount: $amountCents - ${other.amountCents}"
        }
        return Money(amountCents - other.amountCents, currencyCode)
    }

    operator fun times(factor: Int): Money {
        require(factor >= 0) { "Multiplier must be non-negative" }
        return Money(amountCents * factor, currencyCode)
    }

    override fun toString(): String = "${currencyCode} %.2f".format(Locale.US, amountCents / 100.0)

    private fun requireSameCurrency(other: Money) {
        require(currencyCode == other.currencyCode) {
            "Currency mismatch: $currencyCode vs ${other.currencyCode}"
        }
    }

    companion object {
        val ZERO_USD = fromCents(0, "USD")

        fun fromCents(amountCents: Long, currency: String = "USD"): Money {
            return Money(amountCents, currency.uppercase())
        }

        /**
         * Parse from a decimal string like "100.50" → 10050 cents.
         * Uses string manipulation to avoid floating-point errors.
         */
        fun parse(amount: String, currency: String = "USD"): Money {
            val parts = amount.split(".")
            val whole = parts[0].toLongOrNull()
                ?: error("Invalid amount: $amount")
            val frac = if (parts.size > 1) {
                parts[1].padEnd(2, '0').take(2).toLongOrNull()
                    ?: error("Invalid fractional part: ${parts[1]}")
            } else 0L

            return Money(whole * 100 + frac, currency.uppercase())
        }
    }
}