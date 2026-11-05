package com.omnitix.ledger.grpc

import com.omnitix.contracts.common.v1.Money
import com.omnitix.contracts.common.v1.Result
import com.omnitix.contracts.common.v1.Status
import com.omnitix.ledger.domain.ChargeOutcome
import com.omnitix.ledger.domain.RefundOutcome
import com.omnitix.ledger.payment.PaymentLookupResult
import com.google.protobuf.Timestamp

object GrpcProtoConverter {

    fun ok(): Result = Result.newBuilder()
        .setStatus(Status.STATUS_OK)
        .build()

    fun err(status: Status, code: String, message: String): Result = Result.newBuilder()
        .setStatus(status)
        .setErrorCode(code)
        .setErrorMessage(message)
        .build()

    fun money(cents: Long, currency: String): Money = Money.newBuilder()
        .setAmountCents(cents)
        .setCurrency(currency)
        .build()

    fun now(): Timestamp {
        val instant = java.time.Instant.now()
        return Timestamp.newBuilder()
            .setSeconds(instant.epochSecond)
            .setNanos(instant.nano)
            .build()
    }

    fun chargeOutcomeToResult(outcome: ChargeOutcome): Pair<Result, Boolean> =
        when (outcome) {
            is ChargeOutcome.Succeeded -> ok() to true
            is ChargeOutcome.Failed -> err(
                Status.STATUS_ERROR,
                outcome.failureCode,
                outcome.failureMessage,
            ) to false
        }

    fun refundOutcomeToResult(outcome: RefundOutcome): Pair<Result, Boolean> =
        when (outcome) {
            is RefundOutcome.Succeeded -> ok() to true
            is RefundOutcome.Failed -> err(
                Status.STATUS_ERROR,
                outcome.failureCode,
                outcome.failureMessage,
            ) to false
        }

    fun lookupToResult(lookup: PaymentLookupResult?): PaymentLookupResult? = lookup
}