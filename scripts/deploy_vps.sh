#!/usr/bin/env bash
# Deploy Django EduEval lên VPS Long Van và reset DB (chỉ giữ admin).
# Usage: ./scripts/deploy_vps.sh [admin_username]
set -euo pipefail

VPS_HOST="${VPS_HOST:-root@45.119.81.70}"
REMOTE_DIR="${REMOTE_DIR:-/var/www/edueval}"
KEEP_USER="${1:-admin}"
LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"

RSYNC_EXCLUDES=(
  --exclude '.git'
  --exclude 'venv'
  --exclude 'venv_*'
  --exclude '__pycache__'
  --exclude '*.pyc'
  --exclude '.env'
  --exclude 'db.sqlite3'
  --exclude 'media'
  --exclude 'edueval-adonis'
  --exclude 'edueval-laravel'
  --exclude 'laravel-app'
  --exclude '.pa-git-deploy'
  --exclude '.tools'
  --exclude 'node_modules'
)

echo "==> Đồng bộ mã nguồn lên ${VPS_HOST}:${REMOTE_DIR}"
rsync -avz --delete "${RSYNC_EXCLUDES[@]}" \
  "${LOCAL_DIR}/" "${VPS_HOST}:${REMOTE_DIR}/"

echo "==> Migrate + wipe data (giữ ${KEEP_USER}) + restart"
ssh "${VPS_HOST}" bash -s <<REMOTE
set -euo pipefail
cd ${REMOTE_DIR}
source venv/bin/activate
pip install -q -r requirements.txt
python manage.py migrate --noinput
python manage.py wipe_data_keep_admin --username=${KEEP_USER} --noinput
# Xóa file upload (minh chứng, avatar...) — giữ thư mục media/
find media -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
python manage.py collectstatic --noinput 2>/dev/null || true
systemctl restart edueval
systemctl is-active edueval
echo "Deploy xong."
REMOTE
