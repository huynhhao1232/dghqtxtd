#!/usr/bin/env bash
# Entrypoint cho Tentent Vibe Code / Plesk khi họ gọi ./start.sh hoặc npm start.
set -euo pipefail
cd "$(dirname "$0")"

if [[ ! -f .env ]]; then
  if [[ -f .env.production.example ]]; then
    cp .env.production.example .env
  elif [[ -f .env.example ]]; then
    cp .env.example .env
  fi
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  php artisan key:generate --force
fi

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

if [[ ! -L public/storage && ! -d public/storage ]]; then
  php artisan storage:link || true
fi

# Shared hosting thường không có Reverb — tránh crash khi boot
php artisan config:clear || true

PORT="${PORT:-8080}"
exec php artisan serve --host=0.0.0.0 --port="$PORT"
