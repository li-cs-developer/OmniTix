#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# OmniTix — Stop local infrastructure (data preserved)
# ═══════════════════════════════════════════════════════════
set -euo pipefail

cd "$(dirname "$0")/.."

echo "→ Stopping OmniTix infrastructure..."
docker compose down

echo "✅ Stopped. Named volumes preserved."
echo "   Wipe everything: ./scripts/db-reset.sh"