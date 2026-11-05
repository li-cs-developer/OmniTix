package com.omnitix.ledger

import org.springframework.context.annotation.Configuration
import org.springframework.data.jpa.repository.config.EnableJpaRepositories
import org.springframework.transaction.annotation.EnableTransactionManagement

/**
 * JPA configuration for the ledger module.
 *
 * The ledger service owns the `ledger` Postgres schema exclusively.
 * Repositories live under `com.omnitix.ledger.persistence`.
 */
@Configuration
@EnableJpaRepositories(basePackages = ["com.omnitix.ledger.persistence"])
@EnableTransactionManagement
class JpaConfig