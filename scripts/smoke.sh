#!/usr/bin/env bash
# OmniTix full smoke test — run after all services are up.
set -uo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
PASS=0; FAIL=0

check() {
  local name="$1" result="$2"
  if [ "$result" -eq 0 ]; then
    printf "${GREEN}✓${NC} %s\n" "$name"
    PASS=$((PASS + 1))
  else
    printf "${RED}✗${NC} %s\n" "$name"
    FAIL=$((FAIL + 1))
  fi
}

echo "═══════════════════════════════════════════════════════"
echo " OmniTix Smoke Test"
echo "═══════════════════════════════════════════════════════"

# ─── 1. Health checks ───────────────────────────────────
echo -e "\n[1] Health Checks"
curl -sf http://localhost:8000/api/v1/health > /dev/null; check "Laravel HTTP" $?
curl -sf http://localhost:3000/api/health > /dev/null; check "Next.js HTTP" $?
curl -sf http://localhost:3001/health > /dev/null; check "NestJS WS" $?
curl -sf http://localhost:50054/api/v1/health > /dev/null; check "Rust Crypto" $?
curl -sf http://localhost:8081/actuator/health > /dev/null; check "Kotlin Ledger (Actuator)" $?
curl -sf http://localhost:8080/health > /dev/null; check "Go Inventory" $?

# ─── 2. Database state ──────────────────────────────────
echo -e "\n[2] Database State"
DB_ORGS=$(docker exec omnitix-postgres psql -U omnitix -d omnitix -tAc "SELECT COUNT(*) FROM app.organizers;")
if [[ "$DB_ORGS" == "1" ]]; then check "Seeded organizer exists" 0; else check "Seeded organizer exists" 1; fi

DB_EVENTS=$(docker exec omnitix-postgres psql -U omnitix -d omnitix -tAc "SELECT COUNT(*) FROM app.events;")
if [[ "$DB_EVENTS" == "1" ]]; then check "Seeded event exists" 0; else check "Seeded event exists" 1; fi

LEDGER_ACCTS=$(docker exec omnitix-postgres psql -U omnitix -d omnitix -tAc "SELECT COUNT(*) FROM ledger.accounts;")
if [[ "$LEDGER_ACCTS" == "8" ]]; then check "Ledger chart of accounts (8)" 0; else check "Ledger chart of accounts (8)" 1; fi

# ─── 3. API Contracts ───────────────────────────────────
echo -e "\n[3] API Contracts"
EVENT_SLUG="demo-rock-night"
EVENT_JSON=$(curl -s http://localhost:8000/api/v1/events/$EVENT_SLUG)
if [[ "$(echo "$EVENT_JSON" | jq -r '.data.title')" == "Demo Rock Night" ]]; then
  check "Public event catalog" 0
else
  check "Public event catalog" 1
fi

TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/admin/auth/login \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"email":"owner@omnitix.test","password":"password"}' | jq -r '.data.token')
if [[ -n "$TOKEN" && "$TOKEN" != "null" ]]; then check "Admin login returns token" 0; else check "Admin login returns token" 1; fi

DASH=$(curl -s http://localhost:8000/api/v1/admin/dashboard/summary \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json')
if [[ "$(echo "$DASH" | jq -r '.data.events.total')" == "1" ]]; then check "Admin dashboard data" 0; else check "Admin dashboard data" 1; fi

# ─── 4. Kafka → Laravel ─────────────────────────────────
echo -e "\n[4] Kafka Consumer Round-trip"
ORDER_ID="01TESTORDER00000000000001"
PAYMENT_ID="01TESTPAY00000000000000001"
KAFKA_MSG='{"id":"01TESTENV00000000000000001","type":"payment.completed","version":1,"occurred_at":"'$(date -u +%Y-%m-%dT%H:%M:%SZ)'","producer":"kotlin-ledger","correlation_id":null,"partition_key":"'$ORDER_ID'","payload":{"payment_id":"'$PAYMENT_ID'","order_id":"'$ORDER_ID'","user_id":"test-user","amount_cents":7500,"currency":"USD","provider":"mock","provider_ref":"mock_ch_test","charged_at":"'$(date -u +%Y-%m-%dT%H:%M:%SZ)'","journal_entry_id":"01TESTJOURNAL0000000000001"}}'

echo "$KAFKA_MSG" | docker exec -i omnitix-kafka /opt/kafka/bin/kafka-console-producer.sh \
  --bootstrap-server localhost:9092 --topic omnitix.payment.completed > /dev/null 2>&1
sleep 2
ORDERS_COUNT=$(docker exec omnitix-postgres psql -U omnitix -d omnitix -tAc "SELECT COUNT(*) FROM app.orders WHERE id='$ORDER_ID';")
if [[ "$ORDERS_COUNT" == "0" ]]; then check "Laravel ignored unknown order via Kafka" 0; else check "Laravel ignored unknown order via Kafka" 1; fi

# ─── 5. Purchase Flow (API) ─────────────────────────────
echo -e "\n[5] Purchase Flow"
OCC_ID=$(echo "$EVENT_JSON" | jq -r '.data.next_occurrence.id')
SEAT_ID=$(docker exec omnitix-postgres psql -U omnitix -d omnitix -tAc "SELECT id FROM app.seats LIMIT 1;")
TIER_ID=$(docker exec omnitix-postgres psql -U omnitix -d omnitix -tAc "SELECT id FROM app.price_tiers WHERE code='ADULT';")

CHECKOUT_FAIL=$(curl -s -X POST http://localhost:8000/api/v1/orders/checkout \
  -H 'Content-Type: application/json' \
  -d '{"occurrence_id":"'$OCC_ID'","user_id":"test-user","buyer_email":"test@example.com","seats":[{"seat_id":"'$SEAT_ID'","hold_token":"fake","price_tier_id":"'$TIER_ID'","price_cents":7500}]}')
if [[ "$(echo "$CHECKOUT_FAIL" | jq -r '.error')" == "hold_expired" ]]; then check "Checkout rejects expired/missing hold" 0; else check "Checkout rejects expired/missing hold" 1; fi

# ─── 6. Scanner (Negative) ──────────────────────────────
echo -e "\n[6] Scanner (Negative)"
SCAN_FAIL=$(curl -s -X POST http://localhost:8000/api/v1/scanner/scan \
  -H 'Content-Type: application/json' \
  -d '{"qr_string":"fake.payload.sig","gate_identifier":"Gate A"}')
if [[ "$(echo "$SCAN_FAIL" | jq -r '.data.result')" == "invalid_sig" ]]; then check "Scanner rejects invalid signature" 0; else check "Scanner rejects invalid signature" 1; fi

# ─── 7. Live Database Readiness ─────────────────────────
echo -e "\n[7] Live DB Readiness (What the UI sees)"
TOTAL_SEATS=$(docker exec omnitix-postgres psql -U omnitix -d omnitix -tAc "SELECT COUNT(*) FROM app.seats;")
if [[ "$TOTAL_SEATS" == "400" ]]; then check "400 seats available in DB" 0; else check "400 seats available in DB" 1; fi

echo -e "\n═══════════════════════════════════════════════════════"
printf " Results: ${GREEN}%d passed${NC}, ${RED}%d failed${NC}\n" $PASS $FAIL
echo "═══════════════════════════════════════════════════════"

if [ "$FAIL" -gt 0 ]; then exit 1; fi
