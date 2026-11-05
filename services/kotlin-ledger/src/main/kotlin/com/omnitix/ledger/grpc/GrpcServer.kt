package com.omnitix.ledger.grpc

import io.grpc.Server
import io.grpc.ServerBuilder
import io.grpc.protobuf.services.ProtoReflectionService
import org.slf4j.LoggerFactory
import org.springframework.beans.factory.annotation.Value
import org.springframework.context.SmartLifecycle
import org.springframework.stereotype.Component
import java.util.concurrent.TimeUnit

@Component
class GrpcServer(
    private val handler: LedgerGrpcHandler,
    @Value("\${grpc.server.port:50053}") private val port: Int,
    @Value("\${grpc.server.enabled:true}") private val enabled: Boolean,
) : SmartLifecycle {

    private val log = LoggerFactory.getLogger(javaClass)
    private var server: Server? = null
    private var running = false

    override fun start() {
        if (!enabled) {
            log.info("gRPC server disabled (grpc.server.enabled=false) — tests run without a listening socket")
            return
        }
        log.info("starting gRPC server on port {}", port)
        server = ServerBuilder.forPort(port)
            .addService(handler)
            .addService(ProtoReflectionService.newInstance())
            .build()
            .start()

        log.info("gRPC server listening on port {}", port)
        running = true
    }

    override fun stop() {
        val s = server ?: return
        log.info("stopping gRPC server...")
        s.shutdown()
        if (!s.awaitTermination(5, TimeUnit.SECONDS)) {
            log.warn("gRPC graceful shutdown timed out, forcing")
            s.shutdownNow()
        }
        log.info("gRPC server stopped")
        running = false
    }

    override fun isRunning(): Boolean = running
    override fun isAutoStartup(): Boolean = enabled
    override fun getPhase(): Int = Int.MAX_VALUE - 100
}