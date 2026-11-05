package com.omnitix.ledger.grpc

import com.omnitix.contracts.ledger.v1.*
import com.omnitix.ledger.domain.ChargeCommand
import com.omnitix.ledger.domain.LedgerPaymentService
import com.omnitix.ledger.domain.PayoutService
import com.omnitix.ledger.domain.RefundCommand
import com.omnitix.ledger.persistence.JournalEntryRepository
import io.grpc.Status
import io.grpc.stub.StreamObserver
import org.slf4j.LoggerFactory
import org.springframework.stereotype.Component
import java.time.Instant
import java.util.UUID

@Component
class LedgerGrpcHandler(
    private val paymentService: LedgerPaymentService,
    private val payoutService: PayoutService,
    private val entries: JournalEntryRepository,
) : LedgerServiceGrpc.LedgerServiceImplBase() {

    private val log = LoggerFactory.getLogger(javaClass)

    override fun charge(
        request: ChargeRequest,
        responseObserver: StreamObserver<ChargeResponse>,
    ) {
        val correlationId = request.contextOrBuilder?.requestId?.ifEmpty { null }
        val orderId = request.orderId
        val amount = request.amount.amountCents

        log.info("gRPC charge: order={} amount={} corr={}", orderId, amount, correlationId)

        try {
            val command = ChargeCommand(
                paymentId = newId(),
                orderId = orderId,
                organizerId = request.organizerId,
                buyerUserId = request.buyerUserId,
                buyerEmail = request.buyerEmail,
                amountCents = amount,
                currency = request.amount.currency.ifEmpty { "USD" },
                paymentMethodToken = request.paymentMethodToken,
                idempotencyKey = request.idempotencyKey.ifEmpty { newId() },
                ticketRevenueCents = amount,
                feeRevenueCents = 0,
                taxPayableCents = 0,
                correlationId = correlationId,
            )

            val outcome = paymentService.charge(command)
            val (result, success) = GrpcProtoConverter.chargeOutcomeToResult(outcome)

            val builder = ChargeResponse.newBuilder().setResult(result)
            if (success && outcome is com.omnitix.ledger.domain.ChargeOutcome.Succeeded) {
                builder.paymentId = outcome.paymentId
                builder.providerRef = outcome.providerRef
                builder.status = PaymentStatus.PAYMENT_STATUS_SUCCEEDED
                builder.chargedAt = GrpcProtoConverter.now()
            } else {
                builder.status = PaymentStatus.PAYMENT_STATUS_FAILED
                if (outcome is com.omnitix.ledger.domain.ChargeOutcome.Failed) {
                    builder.paymentId = outcome.paymentId
                    builder.failureCode = outcome.failureCode
                    builder.failureMessage = outcome.failureMessage
                    outcome.providerRef?.let { builder.providerRef = it }
                }
            }

            responseObserver.onNext(builder.build())
            responseObserver.onCompleted()
        } catch (e: Exception) {
            log.error("charge failed with exception", e)
            responseObserver.onError(
                Status.INTERNAL.withDescription(e.message).asException()
            )
        }
    }

    override fun refund(
        request: RefundRequest,
        responseObserver: StreamObserver<RefundResponse>,
    ) {
        log.info(
            "gRPC refund: payment={} order={} amount={}",
            request.paymentId, request.orderId, request.amount.amountCents,
        )

        try {
            val command = RefundCommand(
                refundId = newId(),
                paymentId = request.paymentId,
                orderId = request.orderId,
                providerRef = "",                    // looked up from the original charge
                amountCents = request.amount.amountCents,
                reason = request.reason,
                idempotencyKey = newId(),
                affectedTicketIds = request.affectedTicketIdsList,
                correlationId = request.contextOrBuilder?.requestId?.ifEmpty { null },
            )

            val outcome = paymentService.refund(command)
            val (result, success) = GrpcProtoConverter.refundOutcomeToResult(outcome)

            val builder = RefundResponse.newBuilder().setResult(result)
            if (success && outcome is com.omnitix.ledger.domain.RefundOutcome.Succeeded) {
                builder.refundId = outcome.refundId
                builder.providerRef = outcome.providerRef
                builder.refunded = GrpcProtoConverter.money(outcome.refundedAmountCents, "USD")
                builder.refundedAt = GrpcProtoConverter.now()
            } else if (outcome is com.omnitix.ledger.domain.RefundOutcome.Failed) {
                builder.refundId = outcome.refundId
            }

            responseObserver.onNext(builder.build())
            responseObserver.onCompleted()
        } catch (e: Exception) {
            log.error("refund failed with exception", e)
            responseObserver.onError(
                Status.INTERNAL.withDescription(e.message).asException()
            )
        }
    }

    override fun getPayment(
        request: GetPaymentRequest,
        responseObserver: StreamObserver<GetPaymentResponse>,
    ) {
        val lookup = paymentService.lookupPayment(request.paymentId)
        val builder = GetPaymentResponse.newBuilder()

        when (lookup) {
            is com.omnitix.ledger.payment.PaymentLookupResult.Found -> {
                builder.result = GrpcProtoConverter.ok()
                builder.paymentId = lookup.paymentId
                builder.amount = GrpcProtoConverter.money(lookup.amountCents, lookup.currency)
                builder.providerRef = lookup.providerRef
                builder.status = PaymentStatus.PAYMENT_STATUS_SUCCEEDED
                builder.createdAt = GrpcProtoConverter.now()
            }
            is com.omnitix.ledger.payment.PaymentLookupResult.NotFound -> {
                builder.result = GrpcProtoConverter.err(
                    com.omnitix.contracts.common.v1.Status.STATUS_NOT_FOUND,
                    "payment_not_found",
                    "No such payment: ${request.paymentId}",
                )
            }
            null -> {
                builder.result = GrpcProtoConverter.err(
                    com.omnitix.contracts.common.v1.Status.STATUS_NOT_FOUND,
                    "payment_not_found",
                    "No such payment: ${request.paymentId}",
                )
            }
        }

        responseObserver.onNext(builder.build())
        responseObserver.onCompleted()
    }

    override fun getBalance(
        request: GetBalanceRequest,
        responseObserver: StreamObserver<GetBalanceResponse>,
    ) {
        try {
            val balance = paymentService.balanceOf(request.accountCode)
            val response = GetBalanceResponse.newBuilder()
                .setResult(GrpcProtoConverter.ok())
                .setAccountCode(request.accountCode)
                .setBalance(GrpcProtoConverter.money(balance, "USD"))
                .setAsOf(GrpcProtoConverter.now())
                .build()
            responseObserver.onNext(response)
            responseObserver.onCompleted()
        } catch (e: Exception) {
            responseObserver.onNext(
                GetBalanceResponse.newBuilder()
                    .setResult(
                        GrpcProtoConverter.err(
                            com.omnitix.contracts.common.v1.Status.STATUS_NOT_FOUND,
                            "account_not_found",
                            e.message ?: "Unknown error",
                        )
                    )
                    .build()
            )
            responseObserver.onCompleted()
        }
    }

    override fun listJournalEntries(
        request: ListJournalEntriesRequest,
        responseObserver: StreamObserver<ListJournalEntriesResponse>,
    ) {
        try {
            val from = if (request.hasFrom()) {
                Instant.ofEpochSecond(request.from.seconds, request.from.nanos.toLong())
            } else Instant.EPOCH
            val to = if (request.hasTo()) {
                Instant.ofEpochSecond(request.to.seconds, request.to.nanos.toLong())
            } else Instant.now()

            val pageSize = request.pagination.pageSize.takeIf { it > 0 } ?: 50
            val list = entries.findInDateRange(from, to)
                .filter { request.reference.isEmpty() || it.reference == request.reference }
                .take(pageSize)

            val builder = ListJournalEntriesResponse.newBuilder()
                .setResult(GrpcProtoConverter.ok())

            list.forEach { e ->
                val entryBuilder = JournalEntry.newBuilder()
                    .setId(e.id)
                    .setReference(e.reference)
                    .setDescription(e.description)
                    .setPostedAt(
                        com.google.protobuf.Timestamp.newBuilder()
                            .setSeconds(e.postedAt.epochSecond)
                            .setNanos(e.postedAt.nano)
                            .build()
                    )
                e.lines.forEach { line ->
                    entryBuilder.addLines(
                        JournalLine.newBuilder()
                            .setAccountCode(line.accountCode)
                            .setDebitCents(line.debitCents)
                            .setCreditCents(line.creditCents)
                            .setMemo(line.memo ?: "")
                            .build()
                    )
                }
                builder.addEntries(entryBuilder)
            }

            builder.pagination = com.omnitix.contracts.common.v1.PaginationResponse.newBuilder()
                .setHasMore(false)
                .setTotal(list.size)
                .build()

            responseObserver.onNext(builder.build())
            responseObserver.onCompleted()
        } catch (e: Exception) {
            log.error("listJournalEntries failed", e)
            responseObserver.onNext(
                ListJournalEntriesResponse.newBuilder()
                    .setResult(GrpcProtoConverter.err(
                        com.omnitix.contracts.common.v1.Status.STATUS_ERROR,
                        "internal_error",
                        e.message ?: "unknown",
                    ))
                    .build()
            )
            responseObserver.onCompleted()
        }
    }

    override fun health(
        request: HealthRequest,
        responseObserver: StreamObserver<HealthResponse>,
    ) {
        val response = HealthResponse.newBuilder()
            .setHealthy(true)
            .setVersion("0.1.0")
            .setDbConnected(true)
            .setKafkaConnected(true)
            .setPaymentAdapter("mock")
            .setCheckedAt(GrpcProtoConverter.now())
            .build()
        responseObserver.onNext(response)
        responseObserver.onCompleted()
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
        val rand = UUID.randomUUID().toString().replace("-", "").uppercase()
            .filter { it in alphabet }
        sb.append(rand.take(16).padEnd(16, '0'))
        return sb.toString().take(26)
    }
}