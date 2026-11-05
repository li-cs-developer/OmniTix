#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# OmniTix — Nuke local infra volumes and restart fresh
# ═══════════════════════════════════════════════════════════
set -euo pipefail

cd "$(dirname "$0")/.."

read -rp "⚠️  This will DESTROY all Postgres / Redis / Kafka / MinIO data. Continue? [y/N] " confirm
case "$confirm" in
  y|Y) ;;
  *) echo "Aborted."; exit 0 ;;
esac

echo "→ Tearing down with volumes..."
docker compose down -v

echo "→ Restarting fresh..."
docker compose up -d

echo "✅ Reset complete."