#!/usr/bin/env bash
# Start Rocket.Chat (:3000) + phone auth API (:3001) on this machine
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

echo "==> MongoDB + Rocket.Chat"
sudo docker compose up -d

echo "==> Wait for Rocket.Chat :3000"
for i in $(seq 1 60); do
  if curl -sf http://127.0.0.1:3000/api/info >/dev/null; then
    echo "Rocket.Chat is up"
    break
  fi
  sleep 2
done

cd "$ROOT/auth-gateway"
if [[ ! -d node_modules ]]; then
  npm install --omit=dev
fi

node ../scripts/configure-messenger.js
TG_AUTH_BASE="$TG_AUTH_BASE" node ../scripts/deploy-auth-ui.js

echo "==> Auth API on :$PORT"
echo "Open: http://localhost:3000"
echo "Test OTP: $TEST_OTP"
echo "Admin (Rocket.Chat): $RC_ADMIN_USER / $RC_ADMIN_PASS"
exec node server.js
