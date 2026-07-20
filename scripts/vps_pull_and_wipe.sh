#!/usr/bin/env bash
# Chạy TRÊN VPS sau khi SSH: bash scripts/vps_pull_and_wipe.sh [admin_username]
set -euo pipefail

KEEP_USER="${1:-admin}"
APP_DIR="${APP_DIR:-/var/www/edueval}"
BRANCH="${BRANCH:-django-pa}"

cd "${APP_DIR}"
git fetch origin "${BRANCH}"
git reset --hard "origin/${BRANCH}"

source venv/bin/activate
pip install -q -r requirements.txt
python manage.py migrate --noinput
python manage.py wipe_data_keep_admin --username="${KEEP_USER}" --noinput
find media -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
python manage.py collectstatic --noinput 2>/dev/null || true
systemctl restart edueval
systemctl is-active edueval
echo "Deploy + wipe xong. Chỉ còn user: ${KEEP_USER}"
