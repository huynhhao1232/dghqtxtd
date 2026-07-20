# EduEval

Hệ thống đánh giá hiệu quả công việc.

## Hai bản trong repo

| Thư mục | Stack | Ghi chú |
|---------|-------|--------|
| Gốc (`accounts/`, `tasks/`, …) | **Django** | Bản gốc đang dùng |
| [`edueval-laravel/`](edueval-laravel/) | **Laravel 13** | Viết lại parity đầy đủ, phù hợp shared hosting PHP (Tentent) |

Folder `laravel/` (nếu còn) là bản scaffold dở — **bỏ qua**, dùng `edueval-laravel/`.

## Chat nhóm thời gian thực (Django Channels)

Trang **Khu vực nhóm** (`/departments/<id>/interaction/`) có khung **Chat nhóm** (WebSocket).

**Yêu cầu:** Redis đang chạy (channel layer).

```bash
# macOS
brew services start redis
# hoặc
redis-server

# Cài dependency
pip install -r requirements.txt
python manage.py migrate

# Chạy ASGI (WebSocket). Gunicorn WSGI thuần KHÔNG phục vụ WS.
daphne -b 0.0.0.0 -p 8000 edueval.asgi:application
# hoặc (dev):
python manage.py runserver
```

Biến môi trường: `REDIS_URL` (mặc định `redis://127.0.0.1:6379/0`).

**Production (VPS):** dùng **Daphne** (hoặc Uvicorn) làm process ASGI sau Nginx (proxy `Upgrade`/`Connection` cho `/ws/`). Có thể giữ Gunicorn chỉ cho HTTP nếu tách service — đơn giản nhất là một process Daphne cho cả HTTP + WebSocket.

```bash
# Ví dụ trên VPS
sudo apt install -y redis-server
pip install -r requirements.txt
python manage.py migrate
daphne -b 127.0.0.1 -p 8000 edueval.asgi:application
```

## Deploy Django lên PythonAnywhere

Xem mẫu WSGI: [`pythonanywhere_wsgi.py.example`](pythonanywhere_wsgi.py.example).
Biến môi trường: `DJANGO_DEBUG`, `DJANGO_ALLOWED_HOSTS`, `DJANGO_SECRET_KEY`, `DJANGO_CSRF_TRUSTED_ORIGINS`.

> **Lưu ý:** PythonAnywhere free không hỗ trợ WebSocket / Daphne như VPS. Chat realtime cần VPS (ví dụ dghqcvtxtd.site) với Redis + Daphne.

```bash
# Trên Bash console PA (sau khi upload/clone vào ~/DGVC)
mkvirtualenv --python=/usr/bin/python3.10 edueval
cd ~/DGVC
pip install -r requirements.txt
python manage.py migrate
python manage.py seed_demo
python manage.py collectstatic --noinput
```

Demo: `lanhdao` / `Demo@123`

## Media trên Long Van S3 (Django)

Upload (avatar, minh chứng, đính kèm task) dùng **django-storages + boto3** khi `USE_S3=1`. Static files vẫn trên server PA.

1. Tạo bucket trên console Long Van **hoặc** dùng bucket đã có (đặt đúng `AWS_STORAGE_BUCKET_NAME`). Một số gói giới hạn số bucket.

```bash
cp .env.example .env   # điền AccessKey / SecretKey / tên bucket
pip install -r requirements.txt
python manage.py check_s3                  # liệt kê bucket (giống aws s3 ls)
python manage.py check_s3 --create-bucket  # tạo nếu gói còn slot
```

2. Biến môi trường cần set (local `.env` hoặc PA Web → Environment / WSGI):

| Biến | Ví dụ |
|------|--------|
| `USE_S3` | `1` |
| `AWS_ACCESS_KEY_ID` | *(từ Long Van)* |
| `AWS_SECRET_ACCESS_KEY` | *(từ Long Van — không commit)* |
| `AWS_STORAGE_BUCKET_NAME` | `edueval` |
| `AWS_S3_ENDPOINT_URL` | `https://s3-hcm5-r1.longvan.net` |
| `AWS_S3_REGION_NAME` | `us-east-1` |
| `AWS_S3_SIGNATURE_VERSION` | `s3v4` |
| `AWS_S3_ADDRESSING_STYLE` | `path` |

Kiểm tra kết nối: `python manage.py check_s3` (tương đương `aws s3 ls` + kiểm tra bucket).

**PythonAnywhere free:** outbound HTTP đi qua proxy allowlist. Host Long Van
`s3-hcm5-r1.longvan.net` thường **không** nằm trong danh sách → upload đính kèm
gây `ProxyConnectionError` / `Tunnel connection failed: 403 Forbidden` (HTTP 500
khi tạo công việc có file). Trên PA free hãy để `USE_S3=0` trong WSGI (xem
`pythonanywhere_wsgi.py.example`) để media lưu local (`/media/` → `~/DGVC/media`).
Muốn dùng S3 trên PA: nâng cấp tài khoản **hoặc** [xin thêm host vào allowlist](https://help.pythonanywhere.com/pages/RequestingAllowlistAdditions/).

## Chạy Laravel

Xem [`edueval-laravel/DEPLOY.md`](edueval-laravel/DEPLOY.md).

```bash
cd edueval-laravel
composer install
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Demo: `lanhdao` / `Demo@123`
