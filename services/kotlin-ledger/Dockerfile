# ═══════════════════════════════════════════════════════════
# OmniTix Ledger (Multi-stage build for production)
# ═══════════════════════════════════════════════════════════

# ─── Stage 1: Build (Debian-based, glibc native) ────────
FROM eclipse-temurin:25-jdk AS build

RUN apt-get update && apt-get install -y --no-install-recommends \
    bash git curl unzip && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy Gradle wrapper and configs first (for layer caching)
COPY services/kotlin-ledger/gradle ./gradle
COPY services/kotlin-ledger/gradlew ./gradlew
COPY services/kotlin-ledger/gradle.properties ./
COPY services/kotlin-ledger/build.gradle.kts services/kotlin-ledger/settings.gradle.kts ./

# Copy source code (includes proto files in src/main/proto)
COPY services/kotlin-ledger/src ./src

# Build the application
RUN --mount=type=cache,target=/root/.gradle \
    chmod +x gradlew && ./gradlew build -x test --no-daemon

# ─── Stage 2: Runtime (Alpine is fine, no native compilation needed) ─
FROM eclipse-temurin:25-jre-alpine

RUN apk add --no-cache wget

WORKDIR /app

COPY --from=build /app/build/libs/*.jar app.jar

EXPOSE 8080 8081 8082 50053

ENTRYPOINT ["java", "-jar", "app.jar"]