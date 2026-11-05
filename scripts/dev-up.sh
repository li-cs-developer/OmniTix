#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# OmniTix — Start local infrastructure
# ═══════════════════════════════════════════════════════════
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  echo "→ Creating .env from .env.example"
  cp .env.example .env
fi

echo "→ Starting OmniTix infrastructure..."
docker compose up -d

echo "→ Waiting for Postgres to accept connections..."
until docker compose exec -T postgres pg_isready -U omnitix >/dev/null 2>&1; do
  printf "."
  sleep 1
done
echo " ok"

echo "→ Waiting for Redis to respond..."
until docker compose exec -T redis redis-cli ping >/dev/null 2>&1; do
  printf "."
  sleep 1
done
echo " ok"

echo "→ Ensuring test database exists..."
docker compose exec -T postgres psql -U omnitix -tAc \
  "SELECT 1 FROM pg_database WHERE datname='omnitix_test'" | grep -q 1 \
  || docker compose exec -T postgres psql -U omnitix -c \
       "CREATE DATABASE omnitix_test OWNER omnitix;"
echo " ok"

echo ""
echo "✅ OmniTix infrastructure is up."
echo ""
echo "   Postgres:    localhost:5433  (user: omnitix / db: omnitix)"
echo "   Redis:       localhost:6380"
echo "   Kafka:       localhost:29092"
echo "   S3 API:      http://localhost:9000"
echo "   SeaweedFS UI: http://localhost:9001"
echo ""