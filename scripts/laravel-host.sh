#!/usr/bin/env bash
# Run a Laravel artisan command against host infra (127.0.0.1:5433, 6380).
# Usage: ./scripts/laravel-host.sh migrate
set -euo pipefail
cd "$(dirname "$0")/../services/laravel-core"
export DB_HOST=127.0.0.1
export DB_PORT=5433
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6380
export KAFKA_BROKERS_HOST=127.0.0.1:29092
export INVENTORY_HTTP_URL=http://127.0.0.1:8080
export CRYPTO_HTTP_URL=http://127.0.0.1:50054
export LEDGER_GRPC_HTTP_URL=http://127.0.0.1:8082
exec php artisan "$@"