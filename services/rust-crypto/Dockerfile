FROM rust:1.93-alpine AS build
RUN apk add --no-cache musl-dev openssl-dev pkgconfig protobuf
WORKDIR /app

COPY services/rust-crypto/Cargo.toml services/rust-crypto/Cargo.lock ./
COPY contracts /contracts
COPY services/rust-crypto/benches ./benches

# Dummy main to pre-cache dependencies
RUN mkdir src && echo "fn main() {}" > src/main.rs && cargo build --release && rm -rf src

# Real source
COPY services/rust-crypto/src ./src
COPY services/rust-crypto/build.rs ./
RUN cargo build --release

FROM alpine:3.20
RUN apk add --no-cache ca-certificates
WORKDIR /app
COPY --from=build /app/target/release/omnitix-crypto .
COPY services/rust-crypto/keys /keys
ENV KEY_PATH=/keys
ENV KEY_ID=v1
EXPOSE 50052 50054
ENTRYPOINT ["./omnitix-crypto"]
