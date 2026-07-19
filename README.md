# EduEval

Hệ thống đánh giá hiệu quả công việc.

## Hai bản trong repo

| Thư mục | Stack | Ghi chú |
|---------|-------|--------|
| Gốc (`accounts/`, `tasks/`, …) | **Django** | Bản gốc đang dùng |
| [`edueval-laravel/`](edueval-laravel/) | **Laravel 13** | Viết lại parity đầy đủ, phù hợp shared hosting PHP (Tentent) |

Folder `laravel/` (nếu còn) là bản scaffold dở — **bỏ qua**, dùng `edueval-laravel/`.

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
