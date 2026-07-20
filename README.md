# EduEval

Hệ thống đánh giá hiệu quả công việc.

## Hai bản trong repo

| Thư mục | Stack | Ghi chú |
|---------|-------|--------|
| Gốc (`accounts/`, `tasks/`, …) | **Django** | Bản gốc đang dùng |
| [`edueval-laravel/`](edueval-laravel/) | **Laravel 13** | Viết lại parity đầy đủ, phù hợp shared hosting PHP (Tentent) |

Folder `laravel/` (nếu còn) là bản scaffold dở — **bỏ qua**, dùng `edueval-laravel/`.

## Chạy Django local

Dùng **venv trong thư mục DGVC** (không dùng venv project khác như `education-blog/myVenv` — dễ thiếu `django-storages` và lỗi `InvalidStorageError`).

```bash
cd /path/to/DGVC
python3 -m venv venv
source venv/bin/activate   # Windows: venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env       # chỉnh USE_S3 / secret nếu cần
python manage.py migrate
python manage.py runserver
```

Nếu `USE_S3=1` mà chưa cài `django-storages`, settings tự fallback sang media local (có warning trong log) thay vì crash trang.

## Deploy Django lên PythonAnywhere

Xem mẫu WSGI: [`pythonanywhere_wsgi.py.example`](pythonanywhere_wsgi.py.example).
Biến môi trường: `DJANGO_DEBUG`, `DJANGO_ALLOWED_HOSTS`, `DJANGO_SECRET_KEY`, `DJANGO_CSRF_TRUSTED_ORIGINS`.

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
