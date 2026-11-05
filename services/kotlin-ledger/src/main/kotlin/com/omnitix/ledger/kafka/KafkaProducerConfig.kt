package com.omnitix.ledger.kafka

import org.apache.kafka.clients.producer.ProducerConfig
import org.apache.kafka.common.serialization.StringSerializer
import org.springframework.beans.factory.annotation.Value
import org.springframework.context.annotation.Bean
import org.springframework.context.annotation.Configuration
import org.springframework.kafka.core.DefaultKafkaProducerFactory
import org.springframework.kafka.core.KafkaTemplate
import org.springframework.kafka.core.ProducerFactory

/**
 * Explicit producer configuration so we don't rely on Spring Boot's
 * auto-configured KafkaTemplate (which reads a different set of properties).
 *
 * Settings match `application.yml`'s `spring.kafka.producer.*` block:
 * idempotent, acks=all, snappy compression, 5ms linger.
 */
@Configuration
class KafkaProducerConfig(
    @Value("\${spring.kafka.bootstrap-servers}") private val bootstrapServers: String,
    @Value("\${spring.kafka.client-id:omnitix-ledger}") private val clientId: String,
) {
    @Bean
    fun producerFactory(): ProducerFactory<String, String> {
        val props = mapOf(
            ProducerConfig.BOOTSTRAP_SERVERS_CONFIG to bootstrapServers,
            ProducerConfig.CLIENT_ID_CONFIG to clientId,
            ProducerConfig.KEY_SERIALIZER_CLASS_CONFIG to StringSerializer::class.java,
            ProducerConfig.VALUE_SERIALIZER_CLASS_CONFIG to StringSerializer::class.java,
            ProducerConfig.ACKS_CONFIG to "all",
            ProducerConfig.RETRIES_CONFIG to 3,
            ProducerConfig.ENABLE_IDEMPOTENCE_CONFIG to true,
            ProducerConfig.MAX_IN_FLIGHT_REQUESTS_PER_CONNECTION to 5,
            ProducerConfig.LINGER_MS_CONFIG to 5,
            ProducerConfig.COMPRESSION_TYPE_CONFIG to "snappy",
        )
        return DefaultKafkaProducerFactory(props)
    }

    @Bean
    fun kafkaTemplate(): KafkaTemplate<String, String> =
        KafkaTemplate(producerFactory())
}