# ═══════════════════════════════════════════════════════════
# OmniTix Ledger (Multi-stage build for production)
# ═══════════════════════════════════════════════════════════

# ─── Stage 1: Build ─────────────────────────────────────
FROM eclipse-temurin:25-jdk-alpine AS build

# Add gcompat to the package list
RUN apk add --no-cache bash git curl unzip gcompat

WORKDIR /app

# Copy Gradle wrapper and configs first (for layer caching)
COPY services/kotlin-ledger/gradle ./gradle
COPY services/kotlin-ledger/gradlew ./gradlew
COPY services/kotlin-ledger/gradle.properties ./
COPY services/kotlin-ledger/build.gradle.kts services/kotlin-ledger/settings.gradle.kts ./

# Copy source code (now includes the proto files in src/main/proto)
COPY services/kotlin-ledger/src ./src

# Build the application
RUN --mount=type=cache,target=/root/.gradle \
    chmod +x gradlew && ./gradlew build -x test --no-daemon

# ─── Stage 2: Runtime ───────────────────────────────────
FROM eclipse-temurin:25-jre-alpine

RUN apk add --no-cache wget

WORKDIR /app

# Copy the built jar from the build stage
COPY --from=build /app/build/libs/*.jar app.jar

EXPOSE 8080 8081 8082 50053

ENTRYPOINT ["java", "-jar", "app.jar"]
