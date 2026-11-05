import org.jetbrains.kotlin.gradle.tasks.KotlinCompile

plugins {
    id("org.springframework.boot") version "3.5.5"
    id("io.spring.dependency-management") version "1.1.7"
    kotlin("jvm") version "2.4.20"
    kotlin("plugin.spring") version "2.4.20"
    kotlin("plugin.jpa") version "2.4.20"
    id("com.google.protobuf") version "0.9.4"
}

group = "com.omnitix"
version = "0.1.0"

java {
    toolchain {
        languageVersion.set(JavaLanguageVersion.of(25))
    }
}

repositories {
    mavenCentral()
}

// ─── Dependency versions ────────────────────────────────
val grpcVersion = "1.68.1"
val grpcKotlinVersion = "1.4.3"
val protobufVersion = "3.25.5"
val kotlinxCoroutinesVersion = "1.9.0"
val postgresqlVersion = "42.7.4"
val flywayVersion = "10.20.1"

dependencies {
    // ─── Spring Boot ─────────────────────────────────────
    implementation("org.springframework.boot:spring-boot-starter")
    implementation("org.springframework.boot:spring-boot-starter-data-jpa")
    implementation("org.springframework.boot:spring-boot-starter-actuator")
    implementation("org.springframework.boot:spring-boot-starter-validation")
    implementation("org.springframework.boot:spring-boot-starter-web")

    // ─── Logging ─────────────────────────────────────────
    implementation("net.logstash.logback:logstash-logback-encoder:8.0")

    // ─── Kotlin ──────────────────────────────────────────
    implementation("com.fasterxml.jackson.module:jackson-module-kotlin")
    implementation("org.jetbrains.kotlin:kotlin-reflect")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-core:$kotlinxCoroutinesVersion")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-reactor:$kotlinxCoroutinesVersion")

    // ─── gRPC ────────────────────────────────────────────
    implementation("io.grpc:grpc-netty-shaded:$grpcVersion")
    implementation("io.grpc:grpc-protobuf:$grpcVersion")
    implementation("io.grpc:grpc-stub:$grpcVersion")
    implementation("io.grpc:grpc-services:$grpcVersion")
    implementation("io.grpc:grpc-kotlin-stub:$grpcKotlinVersion")
    implementation("com.google.protobuf:protobuf-kotlin:$protobufVersion")
    implementation("com.google.protobuf:protobuf-java-util:$protobufVersion")

    compileOnly("org.apache.tomcat:annotations-api:6.0.53")

    // ─── Database ────────────────────────────────────────
    implementation("org.postgresql:postgresql:$postgresqlVersion")
    implementation("org.flywaydb:flyway-core:$flywayVersion")
    implementation("org.flywaydb:flyway-database-postgresql:$flywayVersion")

    // ─── Kafka ───────────────────────────────────────────
    implementation("org.springframework.kafka:spring-kafka")

    // ─── Test ────────────────────────────────────────────
    testImplementation("org.springframework.boot:spring-boot-starter-test")
    testImplementation("org.jetbrains.kotlin:kotlin-test-junit5")
    testRuntimeOnly("org.junit.platform:junit-platform-launcher")
}

// ─── Protobuf codegen ───────────────────────────────────
protobuf {
    protoc {
        artifact = "com.google.protobuf:protoc:$protobufVersion"
    }
    plugins {
        create("grpc") {
            artifact = "io.grpc:protoc-gen-grpc-java:$grpcVersion"
        }
        create("grpckt") {
            artifact = "io.grpc:protoc-gen-grpc-kotlin:$grpcKotlinVersion:jdk8@jar"
        }
    }
    generateProtoTasks {
        all().forEach { task ->
            task.plugins {
                create("grpc")
                create("grpckt")
            }
            task.builtins {
                create("kotlin")
            }
        }
    }
}

sourceSets {
    main {
        proto {
            srcDir("../../contracts/proto")
        }
    }
}

tasks.withType<KotlinCompile> {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_25)
        freeCompilerArgs.addAll("-Xjsr305=strict")
    }
}

tasks.withType<Test> {
    useJUnitPlatform()
}

// ─── bootRun: default to local profile + host-side URLs ─
// bootRun runs on the host, so Postgres/Redis/Kafka are reachable at
// 127.0.0.1 (via Docker's port mapping), not at the container DNS names.
// Inside Docker/K8s, set SPRING_PROFILES_ACTIVE=prod (or dev) and pass
// the container DNS names via the container's own env; the defaults here
// only apply when the env vars are absent, so production deployments
// override them naturally.
tasks.named<org.springframework.boot.gradle.tasks.run.BootRun>("bootRun") {
    environment("SPRING_PROFILES_ACTIVE", System.getenv("SPRING_PROFILES_ACTIVE") ?: "local")
    environment("DB_HOST", System.getenv("DB_HOST") ?: "127.0.0.1")
    environment("DB_PORT", System.getenv("DB_PORT") ?: "5433")
    environment("KAFKA_BROKERS", System.getenv("KAFKA_BROKERS") ?: "127.0.0.1:29092")
}