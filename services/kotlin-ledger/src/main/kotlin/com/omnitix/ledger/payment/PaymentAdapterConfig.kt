package com.omnitix.ledger.payment

import org.slf4j.LoggerFactory
import org.springframework.beans.factory.annotation.Value
import org.springframework.context.annotation.Bean
import org.springframework.context.annotation.Configuration

@Configuration
class PaymentAdapterConfig {

    private val log = LoggerFactory.getLogger(javaClass)

    @Bean
    fun paymentAdapter(
        @Value("\${ledger.payment.adapter:mock}") configuredAdapter: String,
        @Value("\${ledger.payment.mock.failure-rate:0.0}") failureRate: Double,
        @Value("\${ledger.payment.mock.latency-ms:50}") latencyMs: Long,
        @Value("\${ledger.payment.stripe.api-key:}") stripeApiKey: String,
    ): PaymentAdapter {
        return when (configuredAdapter.lowercase()) {
            "mock" -> {
                log.info("Using payment adapter: mock (failureRate={}, latencyMs={})",
                    failureRate, latencyMs)
                MockPaymentAdapter(failureRate = failureRate, latencyMs = latencyMs)
            }
            "stripe" -> {
                log.warn("Using payment adapter: stripe STUB — will throw on any call")
                StripePaymentAdapter(apiKey = stripeApiKey)
            }
            else -> throw IllegalArgumentException(
                "Unknown payment adapter: '$configuredAdapter'. Expected 'mock' or 'stripe'."
            )
        }
    }
}