#!/usr/bin/env bash
# Đóng gói ZIP deploy Tentent Vibe Code: Laravel ở gốc ZIP (không bọc thư mục con).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="$(cd "$ROOT/.." && pwd)"
ZIP_NAME="edueval-tentent-deploy.zip"
STAGE="$(mktemp -d)"

cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

cd "$ROOT"

echo "==> Composer (production)…"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Build frontend…"
if [[ -f package-lock.json ]]; then
  npm ci --ignore-scripts
else
  npm install --ignore-scripts
fi
npm run build

echo "==> Staging files…"
rsync -a \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='.env.backup' \
  --exclude='node_modules' \
  --exclude='tests' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='database/*.sqlite' \
  --exclude='.phpunit.result.cache' \
  --exclude='Homestead.*' \
  "$ROOT/" "$STAGE/edueval/"

mkdir -p \
  "$STAGE/edueval/storage/app/public" \
  "$STAGE/edueval/storage/framework/cache/data" \
  "$STAGE/edueval/storage/framework/sessions" \
  "$STAGE/edueval/storage/framework/views" \
  "$STAGE/edueval/storage/logs" \
  "$STAGE/edueval/bootstrap/cache"
touch \
  "$STAGE/edueval/storage/app/public/.gitignore" \
  "$STAGE/edueval/storage/logs/.gitignore"

chmod +x "$STAGE/edueval/start.sh" "$STAGE/edueval/artisan"

# .env sẵn để installer không fail vì thiếu APP_KEY (dùng sqlite tạm; đổi MySQL sau trên Plesk)
cp "$ROOT/.env.production.example" "$STAGE/edueval/.env.production.example"
cp "$ROOT/.env.production.example" "$STAGE/edueval/.env"
# Override sang sqlite để deploy 1-click không cần MySQL ngay
{
  echo ""
  echo "DB_CONNECTION=sqlite"
  echo "# DB_HOST="
  echo "# DB_PORT="
  echo "# DB_DATABASE="
  echo "# DB_USERNAME="
  echo "# DB_PASSWORD="
} >> "$STAGE/edueval/.env"
# Xóa các dòng DB_* mysql cũ khỏi .env staging để tránh conflict — viết lại file gọn
cat > "$STAGE/edueval/.env" <<'EOF'
APP_NAME=EduEval
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost

APP_LOCALE=vi
APP_FALLBACK_LOCALE=vi
APP_FAKER_LOCALE=vi_VN
APP_TIMEZONE=Asia/Ho_Chi_Minh

APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=sqlite

SESSION_DRIVER=file
SESSION_LIFETIME=120
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@localhost"
MAIL_FROM_NAME="${APP_NAME}"
EOF

touch "$STAGE/edueval/database/database.sqlite"
(
  cd "$STAGE/edueval"
  php artisan key:generate --force
  php artisan migrate --force --seed
)

echo "==> Creating $OUT_DIR/$ZIP_NAME (Laravel at ZIP root)…"
rm -f "$OUT_DIR/$ZIP_NAME"
(
  cd "$STAGE/edueval"
  zip -qr "$OUT_DIR/$ZIP_NAME" . \
    -x "*.DS_Store" \
    -x "*__MACOSX*"
)

SIZE="$(du -h "$OUT_DIR/$ZIP_NAME" | cut -f1)"
echo "==> Done: $OUT_DIR/$ZIP_NAME ($SIZE)"
echo ""
echo "Nếu 1-Click vẫn lỗi: mở log trong Plesk File Manager:"
echo "  /opt/psa/var/modules/TentenAllInOneInstaller/logs/"
echo "Hoặc deploy thủ công PHP (Document Root = public/) — xem DEPLOY.md"
