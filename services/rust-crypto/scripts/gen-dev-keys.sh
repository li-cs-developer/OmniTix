#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# Generate a dev Ed25519 keypair for OmniTix crypto service.
# Writes ./keys/ed25519_v1.{private,public}
# ═══════════════════════════════════════════════════════════
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p keys

KEY_ID="${1:-v1}"

if [ -f "keys/ed25519_${KEY_ID}.private" ]; then
  echo "Key ${KEY_ID} already exists. Refusing to overwrite."
  exit 1
fi

# Generate a random 32-byte seed
SEED=$(openssl rand -hex 32)
echo "$SEED" > "keys/ed25519_${KEY_ID}.private"
chmod 600 "keys/ed25519_${KEY_ID}.private"

# Derive the public key — use a tiny Rust helper or Python
python3 - <<EOF
import sys
try:
    from nacl.signing import SigningKey
except ImportError:
    print("Install pynacl: pip install pynacl", file=sys.stderr)
    sys.exit(1)

seed = bytes.fromhex("$SEED")
sk = SigningKey(seed)
vk = sk.verify_key
open("keys/ed25519_${KEY_ID}.public", "w").write(vk.encode().hex())
print("Generated key: ${KEY_ID}")
print("  private: keys/ed25519_${KEY_ID}.private")
print("  public:  keys/ed25519_${KEY_ID}.public")
EOF