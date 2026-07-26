#!/usr/bin/env bash
# نصب کامل روی اوبونتو: Rocket.Chat + ورود با موبایل/کد تست 1234
# اجرا: bash install-on-ubuntu.sh
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_URL="${ROOT_URL:-http://localhost:3000}"

echo "==> نصب Docker (در صورت نیاز)"
if ! command -v docker >/dev/null 2>&1; then
  sudo apt-get update
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io docker-compose-v2 curl
  sudo systemctl enable --now docker
  sudo usermod -aG docker "$USER" || true
  echo "اگر تازه به گروه docker اضافه شدی، یک‌بار logout/login کن."
fi

if ! command -v node >/dev/null 2>&1; then
  echo "==> نصب Node.js 20"
  curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs
fi

echo "==> بالا آوردن MongoDB + Rocket.Chat"
cd "$DIR"
sudo docker compose up -d

echo "==> صبر برای آماده شدن Rocket.Chat"
for i in $(seq 1 90); do
  if curl -sf http://127.0.0.1:3000/api/info >/dev/null; then
    break
  fi
  sleep 2
done

if ! curl -sf http://127.0.0.1:3000/api/info >/dev/null; then
  echo "Rocket.Chat بالا نیامد. لاگ:"
  sudo docker logs rocketchat-app 2>&1 | tail -40
  exit 1
fi

# اگر هنوز setup wizard تمام نشده، کاربر باید یک‌بار از مرورگر ادمین بسازد
# بعد رمز ادمین را در env بگذارید.

echo "==> نصب وابستگی auth-gateway"
cd "$DIR/auth-gateway"
npm install --omit=dev

echo "==> اعمال تنظیمات پیام‌رسان + UI ورود موبایل"
cd "$DIR"
node scripts/configure-messenger.js
TG_AUTH_BASE=http://127.0.0.1:3001 node scripts/deploy-auth-ui.js

echo ""
echo "============================================"
echo " آماده است (روی همین سیستم)"
echo " 1) Rocket.Chat:  http://localhost:3000"
echo " 2) Auth API:     http://localhost:3001/tg-auth/health"
echo ""
echo " برای روشن ماندن API:"
echo "   cd $DIR && bash scripts/start-stack.sh"
echo "   یا فقط API:"
echo "   cd $DIR/auth-gateway && PORT=3001 RC_URL=http://127.0.0.1:3000 node server.js"
echo ""
echo " کد تست OTP: 1234"
echo " ادمین پیش‌فرض این محیط کلود: mrkd / Admin123!"
echo " (روی سیستم خودت همان ادمینی که در ویزارد ساختی)"
echo "============================================"
