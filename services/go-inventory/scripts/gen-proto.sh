#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# OmniTix — Generate Go stubs from .proto contracts
# Requires: buf, protoc-gen-go, protoc-gen-go-grpc (via `go install`)
# ═══════════════════════════════════════════════════════════
set -euo pipefail

cd "$(dirname "$0")/.."

PROTO_DIR="../../contracts/proto"
GEN_DIR="gen/go"

echo "→ Regenerating protobuf stubs..."
echo "  Source: $PROTO_DIR"
echo "  Output: $GEN_DIR"

cd "$PROTO_DIR"

# buf generate uses buf.gen.yaml (to be created below)
buf generate --template buf.gen.yaml

echo "✅ Protobuf stubs regenerated."