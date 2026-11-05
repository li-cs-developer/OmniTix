# ═══════════════════════════════════════════════════════════
# OmniTix — Go Inventory Engine
# ═══════════════════════════════════════════════════════════

.PHONY: help install build run dev test lint clean gen-proto

# Host-mapped docker-compose infra addresses. These override the
# container-DNS defaults so `make run` works from the host without
# needing a .env.local file. Remove or override to run inside Docker.
HOST_ENV = \
    REDIS_HOST=127.0.0.1 \
    REDIS_PORT=6380 \
    KAFKA_BROKERS=127.0.0.1:29092

# Show available targets
help:
	@echo "OmniTix Go Inventory Engine"
	@echo ""
	@echo "Available targets:"
	@echo "  install     Download dependencies"
	@echo "  build       Compile binary to bin/inventory"
	@echo "  run         Run server locally (uses .env)"
	@echo "  dev         Run with air live-reload (uses .env)"
	@echo "  test        Run unit tests"
	@echo "  lint        Run golangci-lint"
	@echo "  gen-proto   Regenerate protobuf Go stubs via buf"
	@echo "  clean       Remove build artifacts"

install:
	go mod download
	go mod tidy

build:
	go mod tidy
	go build -o bin/inventory ./cmd/server

run:
	go mod tidy
	$(HOST_ENV) go run ./cmd/server

dev:
	$(HOST_ENV) air

test:
	go test -race -count=1 ./...

lint:
	golangci-lint run

gen-proto:
	cd ../../contracts/proto && buf generate

clean:
	rm -rf bin/ gen/