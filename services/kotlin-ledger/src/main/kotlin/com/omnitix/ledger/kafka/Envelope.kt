package com.omnitix.ledger.kafka

import com.fasterxml.jackson.annotation.JsonProperty
import java.time.Instant

/**
 * Standard OmniTix event envelope, matching `events/_envelope.schema.json`
 * in the monorepo root.
 *
 * Every Kafka event from every service carries this shape, so consumers can
 * uniformly:
 *   - dedupe on `id`
 *   - route on `type`
 *   - trace on `correlation_id`
 *   - version on `version`
 */
data class Envelope(
    @JsonProperty("id") val id: String,
    @JsonProperty("type") val type: String,
    @JsonProperty("version") val version: Int = 1,
    @JsonProperty("occurred_at") val occurredAt: String,
    @JsonProperty("producer") val producer: String,
    @JsonProperty("correlation_id") val correlationId: String? = null,
    @JsonProperty("partition_key") val partitionKey: String? = null,
    @JsonProperty("payload") val payload: Any,
)