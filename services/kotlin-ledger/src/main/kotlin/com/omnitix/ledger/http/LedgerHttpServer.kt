package com.omnitix.ledger.http

import com.omnitix.ledger.domain.LedgerPaymentService
import com.fasterxml.jackson.databind.ObjectMapper
import com.fasterxml.jackson.module.kotlin.jacksonObjectMapper
import com.sun.net.httpserver.HttpExchange
import com.sun.net.httpserver.HttpServer
import org.slf4j.LoggerFactory
import org.springframework.beans.factory.annotation.Value
import org.springframework.context.SmartLifecycle
import org.springframework.stereotype.Component
import java.net.InetSocketAddress

@Component
class LedgerHttpServer(
    private val paymentService: LedgerPaymentService,
    @Value("\${ledger.http.port:8082}") private val port: Int,
    @Value("\${ledger.http.enabled:true}") private val enabled: Boolean,
) : SmartLifecycle {

    private val log = LoggerFactory.getLogger(javaClass)
    private val mapper: ObjectMapper = jacksonObjectMapper()
    private var server: HttpServer? = null
    private var running = false

    override fun start() {
        if (!enabled) {
            log.info("HTTP gateway disabled (ledger.http.enabled=false) — no socket will be bound")
            return
        }
        log.info("starting HTTP gateway on port {}", port)
        val s = HttpServer.create(InetSocketAddress(port), 0)

        s.createContext("/api/v1/charge") { exchange -> handleCharge(exchange) }
        s.createContext("/api/v1/refund") { exchange -> handleRefund(exchange) }
        s.createContext("/api/v1/health") { exchange ->
            val body = """{"status":"ok","service":"kotlin-ledger"}"""
            writeJson(exchange, 200, body)
        }

        s.executor = null
        s.start()
        server = s
        running = true
        log.info("HTTP gateway listening on port {}", port)
    }

    override fun stop() {
        server?.stop(0)
        running = false
        log.info("HTTP gateway stopped")
    }

    override fun isRunning(): Boolean = running
    override fun isAutoStartup(): Boolean = enabled
    override fun getPhase(): Int = Int.MAX_VALUE - 100

    private fun handleCharge(exchange: HttpExchange) {
        try {
            val body = exchange.requestBody.readBytes().toString(Charsets.UTF_8)
            @Suppress("UNCHECKED_CAST")
            val req = mapper.readValue(body, Map::class.java) as Map<String, Any>

            val orderId = req["order_id"] as? String ?: ""
            val amountCents = (req["amount_cents"] as? Number)?.toLong() ?: 0L
            val currency = req["currency"] as? String ?: "USD"
            val buyerEmail = req["buyer_email"] as? String ?: ""
            val methodToken = req["payment_method_token"] as? String ?: "tok_test"

            if (orderId.isEmpty() || amountCents <= 0L) {
                writeJson(exchange, 400, """{"success":false,"error":"invalid_request","message":"order_id and amount_cents required"}""")
                return
            }

            val outcome = paymentService.charge(
                com.omnitix.ledger.domain.ChargeCommand(
                    paymentId = newId(),
                    orderId = orderId,
                    organizerId = "",
                    buyerUserId = "",
                    buyerEmail = buyerEmail,
                    amountCents = amountCents,
                    currency = currency,
                    paymentMethodToken = methodToken,
                    idempotencyKey = orderId,
                    ticketRevenueCents = amountCents,
                    feeRevenueCents = 0,
                    taxPayableCents = 0,
                )
            )

            when (outcome) {
                is com.omnitix.ledger.domain.ChargeOutcome.Succeeded -> {
                    val json = mapper.writeValueAsString(mapOf(
                        "success" to true,
                        "payment_id" to outcome.paymentId,
                        "provider_ref" to outcome.providerRef,
                        "amount_cents" to outcome.chargedAmountCents,
                        "currency" to outcome.currency,
                        "journal_entry_id" to outcome.journalEntryId,
                    ))
                    writeJson(exchange, 200, json)
                }
                is com.omnitix.ledger.domain.ChargeOutcome.Failed -> {
                    val json = mapper.writeValueAsString(mapOf(
                        "success" to false,
                        "error" to outcome.failureCode,
                        "message" to outcome.failureMessage,
                    ))
                    writeJson(exchange, 402, json)
                }
            }
        } catch (e: Exception) {
            log.error("HTTP charge error", e)
            writeJson(exchange, 500, """{"success":false,"error":"internal","message":"${e.message}"}""")
        }
    }

    private fun handleRefund(exchange: HttpExchange) {
        try {
            val body = exchange.requestBody.readBytes().toString(Charsets.UTF_8)
            @Suppress("UNCHECKED_CAST")
            val req = mapper.readValue(body, Map::class.java) as Map<String, Any>

            val refundId = req["refund_id"] as? String ?: ""
            val paymentId = req["payment_id"] as? String ?: ""
            val orderId = req["order_id"] as? String ?: ""
            val amountCents = (req["amount_cents"] as? Number)?.toLong() ?: 0L
            val reason = req["reason"] as? String ?: "Refund"
            @Suppress("UNCHECKED_CAST")
            val affectedTicketIds =
                (req["affected_ticket_ids"] as? List<*>)?.mapNotNull { it as? String } ?: emptyList()

            if (refundId.isEmpty() || amountCents <= 0L) {
                writeJson(exchange, 400, """{"success":false,"error":"invalid_request","message":"refund_id and amount_cents required"}""")
                return
            }

            val outcome = paymentService.refund(
                com.omnitix.ledger.domain.RefundCommand(
                    refundId = refundId,
                    paymentId = paymentId,
                    orderId = orderId,
                    providerRef = "",
                    amountCents = amountCents,
                    reason = reason,
                    idempotencyKey = "http-refund:$refundId",
                    affectedTicketIds = affectedTicketIds,
                ),
            )

            when (outcome) {
                is com.omnitix.ledger.domain.RefundOutcome.Succeeded -> {
                    val json = mapper.writeValueAsString(mapOf(
                        "success" to true,
                        "refund_id" to outcome.refundId,
                        "provider_ref" to outcome.providerRef,
                        "refunded_amount_cents" to outcome.refundedAmountCents,
                        "currency" to "USD",
                        "journal_entry_id" to outcome.journalEntryId,
                        "reason" to reason,
                        "payment_id" to paymentId,
                    ))
                    writeJson(exchange, 200, json)
                    log.info(
                        "HTTP refund: refundId={} paymentId={} orderId={} amount={} → SUCCESS",
                        refundId, paymentId, orderId, amountCents,
                    )
                }
                is com.omnitix.ledger.domain.RefundOutcome.Failed -> {
                    val json = mapper.writeValueAsString(mapOf(
                        "success" to false,
                        "error" to outcome.failureCode,
                        "message" to outcome.failureMessage,
                    ))
                    writeJson(exchange, 422, json)
                }
            }
        } catch (e: Exception) {
            log.error("HTTP refund error", e)
            writeJson(exchange, 500, """{"success":false,"error":"internal","message":"${e.message}"}""")
        }
    }

    private fun writeJson(exchange: HttpExchange, status: Int, body: String) {
        exchange.responseHeaders.add("Content-Type", "application/json")
        exchange.sendResponseHeaders(status, body.toByteArray().size.toLong())
        exchange.responseBody.use { it.write(body.toByteArray()) }
    }

    private fun newId(): String {
        val alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
        val ts = System.currentTimeMillis()
        val sb = StringBuilder(26)
        var v = ts
        repeat(10) {
            sb.insert(0, alphabet[(v and 31).toInt()])
            v = v shr 5
        }
        val rand = java.util.UUID.randomUUID().toString().replace("-", "").uppercase()
            .filter { it in alphabet }
        sb.append(rand.take(16).padEnd(16, '0'))
        return sb.toString().take(26)
    }
}