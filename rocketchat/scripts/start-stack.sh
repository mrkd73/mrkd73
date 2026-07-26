#!/usr/bin/env bash
# فقط لوکال: Rocket.Chat :3000 + Auth API :3001
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export RC_URL="${RC_URL:-http://127.0.0.1:3000}"
export PORT="${AUTH_PORT:-3001}"
export MONGO_URL="${MONGO_URL:-mongodb://127.0.0.1:27017/rocketchat?replicaSet=rs0&directConnection=true}"
export RC_ADMIN_USER="${RC_ADMIN_USER:-mrkd}"
export RC_ADMIN_PASS="${RC_ADMIN_PASS:-Admin123!}"
export TEST_OTP="${TEST_OTP:-1234}"
export TG_AUTH_BASE="${TG_AUTH_BASE:-http://127.0.0.1:3001}"

echo "==> Docker: MongoDB + Rocket.Chat"
sudo docker compose up -d

echo "==> Waiting for http://127.0.0.1:3000 ..."
for i in $(seq 1 90); do
  if curl -sf http://127.0.0.1:3000/api/info >/dev/null; then
    echo "Rocket.Chat OK"
    break
  fi
  sleep 2
done

if ! curl -sf http://127.0.0.1:3000/api/info >/dev/null; then
  echo "ERROR: Rocket.Chat did not start. Check: sudo docker logs rocketchat-app"
  exit 1
fi

cd "$ROOT/auth-gateway"
if [[ ! -d node_modules ]]; then
  npm install --omit=dev
fi

node ../scripts/configure-messenger.js
TG_AUTH_BASE="$TG_AUTH_BASE" node ../scripts/deploy-auth-ui.js

echo ""
echo "========================================"
echo " فقط روی همین سیستم:"
echo "   http://localhost:3000"
echo " کد تست: $TEST_OTP"
echo " ادمین RC: $RC_ADMIN_USER"
echo "========================================"
exec env PORT="$PORT" RC_URL="$RC_URL" RC_ADMIN_USER="$RC_ADMIN_USER" RC_ADMIN_PASS="$RC_ADMIN_PASS" TEST_OTP="$TEST_OTP" node server.js
