package com.omnitix.ledger.kafka

import com.fasterxml.jackson.databind.ObjectMapper
import org.slf4j.LoggerFactory
import org.springframework.kafka.core.KafkaTemplate
import org.springframework.stereotype.Service
import java.time.Instant
import java.util.UUID

/**
 * Emits ledger-domain events to Kafka.
 *
 * The topics and payload shapes match:
 *   - events/payment.completed.schema.json
 *   - events/payment.failed.schema.json
 *   - events/refund.issued.schema.json
 *   - events/payout.settled.schema.json
 *
 * All methods are best-effort: if Kafka is down, the ledger entry is
 * still committed. A daily reconciliation job (see ReconciliationService)
 * catches cases where the ledger and downstream state drift.
 */
@Service
class LedgerEventPublisher(
    private val kafkaTemplate: KafkaTemplate<String, String>,
    private val objectMapper: ObjectMapper,
) {
    private val log = LoggerFactory.getLogger(javaClass)

    private companion object {
        const val PRODUCER = "kotlin-ledger"
        const val TOPIC_PREFIX = "omnitix"
    }

    fun publishPaymentCompleted(
        paymentId: String,
        orderId: String,
        userId: String,
        amountCents: Long,
        currency: String,
        provider: String,
        providerRef: String,
        journalEntryId: String,
        correlationId: String?,
    ) {
        publish(
            type = "payment.completed",
            partitionKey = orderId,
            correlationId = correlationId,
            payload = mapOf(
                "payment_id" to paymentId,
                "order_id" to orderId,
                "user_id" to userId,
                "amount_cents" to amountCents,
                "currency" to currency,
                "provider" to provider,
                "provider_ref" to providerRef,
                "charged_at" to Instant.now().toString(),
                "journal_entry_id" to journalEntryId,
            ),
        )
    }

    fun publishPaymentFailed(
        orderId: String,
        userId: String,
        amountCents: Long,
        currency: String,
        provider: String,
        failureCode: String,
        failureMessage: String,
        correlationId: String?,
    ) {
        publish(
            type = "payment.failed",
            partitionKey = orderId,
            correlationId = correlationId,
            payload = mapOf(
                "order_id" to orderId,
                "user_id" to userId,
                "amount_cents" to amountCents,
                "currency" to currency,
                "provider" to provider,
                "failure_code" to failureCode,
                "failure_message" to failureMessage,
                "failed_at" to Instant.now().toString(),
            ),
        )
    }

    fun publishRefundIssued(
        refundId: String,
        paymentId: String,
        orderId: String,
        amountCents: Long,
        currency: String,
        reason: String,
        providerRef: String?,
        affectedTicketIds: List<String>,
        correlationId: String?,
    ) {
        publish(
            type = "refund.issued",
            partitionKey = orderId,
            correlationId = correlationId,
            payload = mapOf(
                "refund_id" to refundId,
                "payment_id" to paymentId,
                "order_id" to orderId,
                "amount_cents" to amountCents,
                "currency" to currency,
                "reason" to reason,
                "provider_ref" to providerRef,
                "refunded_at" to Instant.now().toString(),
                "affected_ticket_ids" to affectedTicketIds,
            ),
        )
    }

    fun publishPayoutSettled(
        payoutId: String,
        organizerId: String,
        periodStart: Instant,
        periodEnd: Instant,
        grossCents: Long,
        feesCents: Long,
        taxCents: Long,
        netCents: Long,
        currency: String,
        journalEntryId: String,
    ) {
        publish(
            type = "payout.settled",
            partitionKey = organizerId,
            correlationId = null,
            payload = mapOf(
                "payout_id" to payoutId,
                "organizer_id" to organizerId,
                "period_start" to periodStart.toString(),
                "period_end" to periodEnd.toString(),
                "gross_cents" to grossCents,
                "fees_cents" to feesCents,
                "tax_cents" to taxCents,
                "net_cents" to netCents,
                "currency" to currency,
                "journal_entry_id" to journalEntryId,
                "settled_at" to Instant.now().toString(),
            ),
        )
    }

    // ─── Internal ────────────────────────────────────────────

    private fun publish(
        type: String,
        partitionKey: String,
        correlationId: String?,
        payload: Any,
    ) {
        try {
            val envelope = Envelope(
                id = newEventId(),
                type = type,
                version = 1,
                occurredAt = Instant.now().toString(),
                producer = PRODUCER,
                correlationId = correlationId,
                partitionKey = partitionKey,
                payload = payload,
            )
            val json = objectMapper.writeValueAsString(envelope)
            val topic = "$TOPIC_PREFIX.$type"

            kafkaTemplate.send(topic, partitionKey, json).whenComplete { _, ex ->
                if (ex != null) {
                    log.error("kafka publish failed: topic={} id={} err={}",
                        topic, envelope.id, ex.message)
                } else {
                    log.debug("kafka publish ok: topic={} id={}", topic, envelope.id)
                }
            }
        } catch (e: Exception) {
            log.error("kafka publish threw: type={} err={}", type, e.message, e)
        }
    }

    private fun newEventId(): String {
        // ULID-shaped: 26 Crockford base32 chars
        val alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
        val ts = System.currentTimeMillis()
        val sb = StringBuilder(26)
        var v = ts
        repeat(10) {
            sb.insert(0, alphabet[(v and 31).toInt()])
            v = v shr 5
        }
        val rand = UUID.randomUUID().toString().replace("-", "").uppercase()
            .filter { it in alphabet }
        sb.append(rand.take(16).padEnd(16, '0'))
        return sb.toString().take(26)
    }
}