#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# OmniTix — Create Kafka topics (idempotent)
# ═══════════════════════════════════════════════════════════
set -euo pipefail

KAFKA_CONTAINER="${KAFKA_CONTAINER:-omnitix-kafka}"
BROKER="${BROKER:-localhost:9092}"
KT="/opt/kafka/bin/kafka-topics.sh"

create_topic() {
  local name="$1"
  local partitions="${2:-3}"
  local retention_ms="${3:-604800000}"   # 7 days default

  docker exec "$KAFKA_CONTAINER" "$KT" \
    --bootstrap-server "$BROKER" \
    --create \
    --if-not-exists \
    --topic "$name" \
    --partitions "$partitions" \
    --replication-factor 1 \
    --config "retention.ms=$retention_ms" \
    >/dev/null

  echo "  ✅ $name (partitions=$partitions)"
}

echo "→ Creating OmniTix Kafka topics..."

create_topic "omnitix.seat.held"
create_topic "omnitix.seat.expired"
create_topic "omnitix.seat.sold"
create_topic "omnitix.ga.held"
create_topic "omnitix.ga.expired"
create_topic "omnitix.ga.sold"
create_topic "omnitix.payment.completed"
create_topic "omnitix.payment.failed"
create_topic "omnitix.refund.issued"
create_topic "omnitix.ticket.issued"
create_topic "omnitix.ticket.scanned"
create_topic "omnitix.payout.settled"

echo ""
echo "✅ All topics created."
echo ""
echo "List topics:"
echo "  docker exec omnitix-kafka $KT --bootstrap-server $BROKER --list"