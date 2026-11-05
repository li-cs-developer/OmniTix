# syntax=docker/dockerfile:1.7
FROM eclipse-temurin:25-jdk-alpine AS build
RUN apk add --no-cache bash git curl unzip
WORKDIR /app
COPY services/kotlin-ledger/gradle ./gradle
COPY services/kotlin-ledger/gradlew ./gradlew
COPY services/kotlin-ledger/gradle.properties ./
COPY services/kotlin-ledger/build.gradle.kts services/kotlin-ledger/settings.gradle.kts ./
COPY contracts /contracts
COPY services/kotlin-ledger/src ./src
RUN --mount=type=cache,target=/root/.gradle \
    chmod +x gradlew && ./gradlew build -x test --no-daemon

FROM eclipse-temurin:25-jre-alpine
RUN apk add --no-cache wget
WORKDIR /app
COPY --from=build /app/build/libs/*.jar app.jar
EXPOSE 50053 8081 8082
HEALTHCHECK --interval=30s --timeout=3s --start-period=45s \
  CMD wget -qO- http://localhost:8081/actuator/health || exit 1
ENTRYPOINT ["java", "-jar", "/app/app.jar"]
