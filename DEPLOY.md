# EduEval Laravel — Deploy Tentent (Vibe Code Hosting)

Ứng dụng: thư mục `edueval-laravel/` (Laravel 13, PHP 8.3+).

> **Lưu ý quan trọng:** Vibe Code Hosting của Tentent tối ưu cho deploy 1-click (ZIP / GitHub) và có hỗ trợ Laravel. Real-time WebSocket (Reverb) thường **không** chạy ổn định trên hosting shared — trên production nên dùng thông báo database (chuông vẫn hoạt động khi F5 / gọi API), tắt Reverb.

Tài liệu Tentent: [Hướng dẫn Vibe Code Hosting](https://help.tenten.vn/huong-dan-su-dung-vibe-code-hosting/)

---

## Tài khoản demo (sau seed)

| Username   | Mật khẩu   | Vai trò              |
|------------|------------|----------------------|
| lanhdao    | Demo@123   | Lãnh đạo             |
| nhanvien1  | Demo@123   | Trưởng Tổ Toán-Tin   |
| nhanvien2  | Demo@123   | Trưởng Tổ Văn        |
| nhanvien3  | Demo@123   | Nhân viên            |
| admin      | Admin@123  | Quản trị             |

---

### Nếu gặp lỗi `Command failed with exit code 1`

Installer Tentent ghi log tại:

`/opt/psa/var/modules/TentenAllInOneInstaller/logs/deploy-….log`

**Cách xem log:**

1. Plesk → **Files** / File Manager (hoặc SSH).
2. Mở đúng đường dẫn trên (cần quyền admin/root; nếu không thấy, gửi ticket Tentent kèm tên file log).
3. Copy nội dung lỗi (thường gần cuối file) để biết lệnh nào fail (`npm start`, `composer`, PHP version…).

**Nguyên nhân hay gặp với Laravel trên Vibe Code:**

- Plugin nhận `package.json` rồi chạy như app Node nhưng thiếu script `start` → đã bổ sung `start.sh` + `"start": "bash start.sh"`.
- Thiếu `APP_KEY` / DB khi chạy artisan trong lúc deploy → ZIP mới có `.env` + sqlite đã migrate sẵn.
- PHP hosting < 8.3 trong khi Laravel 13 yêu cầu PHP 8.3+.

**Nếu 1-Click vẫn fail:** dùng **Cách C** (hosting PHP / Document Root = `public/`) — ổn định hơn cho Laravel hơn Vibe Code (thiết kế nghiêng Node.js).

---

## Cách A — Deploy bằng ZIP lên Vibe Code (khuyến nghị nhanh)

### 1. Chuẩn bị gói trên máy local

Trong thư mục `edueval-laravel`:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Tạo file ZIP sao cho **nội dung Laravel nằm ngay gốc ZIP** (không bọc thêm thư mục `edueval-laravel/`):

- Có: `artisan`, `composer.json`, `app/`, `public/`, `vendor/`, `public/build/`, …
- Không có: `node_modules/`, `.env`, `.git/`

Hoặc dùng script có sẵn:

```bash
bash scripts/make-tentent-zip.sh
```

File ra: `../edueval-tentent-deploy.zip`

### 2. Trên Tentent Plesk

1. Trỏ domain / subdomain về hosting (DNS).
2. Mở plugin **Tenten 1-Click Launch Website**.
3. **Thêm dự án** → chọn upload **file ZIP**.
4. Upload `edueval-tentent-deploy.zip`.
5. Chọn domain / tạo subdomain → **Triển khai**.
6. Bật **AutoSSL** nếu chưa có HTTPS.

### 3. Cấu hình sau khi deploy

1. Trong Plesk: tạo **Database MySQL** + user, ghi nhớ host/user/pass/db.
2. Copy `.env.production.example` → `.env` (hoặc tạo `.env` mới) với nội dung mẫu bên dưới.
3. Trỏ **Document Root** của domain tới thư mục `public` của project (Plesk → Hosting Settings → Document root = `.../public`). Đây là bước bắt buộc với Laravel.
4. SSH / Terminal Plesk tại thư mục project:

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force   # tùy chọn: dữ liệu demo
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

5. Quyền ghi: `storage/` và `bootstrap/cache/` phải writable.

### Mẫu `.env` production

```env
APP_NAME=EduEval
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.tld
APP_TIMEZONE=Asia/Ho_Chi_Minh
APP_LOCALE=vi
APP_FALLBACK_LOCALE=vi

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ten_db
DB_USERNAME=ten_user
DB_PASSWORD=mat_khau

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

# Shared hosting: tắt Reverb, chuông vẫn dùng API database
BROADCAST_CONNECTION=log

LOG_CHANNEL=stack
LOG_LEVEL=error
```

---

## Cách B — Deploy qua GitHub

1. Đẩy repo (chỉ thư mục Laravel ở root, hoặc cấu hình monorepo nếu Tentent hỗ trợ subfolder).
2. Trong 1-Click Launch: dán URL GitHub (public) hoặc authorize private repo.
3. Chọn domain → Triển khai.
4. Làm tiếp bước cấu hình `.env`, Document Root `public`, migrate như **Cách A**.

Nút **Đồng bộ** trên panel sẽ `git pull` khi bạn cập nhật code.

---

## Cách C — Hosting PHP truyền thống Tentent (không dùng 1-Click)

1. Upload toàn bộ `edueval-laravel` lên server.
2. Document Root → `.../public`.
3. Tạo MySQL + `.env` như trên.
4. Chạy `composer install` (nếu chưa có `vendor`) + các lệnh `artisan` như trên.

---

## Checklist lỗi thường gặp

| Triệu chứng | Cách xử lý |
|-------------|------------|
| 404 mọi route | Document root chưa trỏ `public/` hoặc thiếu `.htaccess` |
| 500 / blank trắng | Xem `storage/logs/laravel.log`; thiếu `APP_KEY`; quyền `storage` |
| Lỗi database | Sai `DB_*` trong `.env`; chưa `migrate` |
| CSS/JS mất | Chưa `npm run build` / thiếu `public/build` |
| Upload file lỗi | Chưa `storage:link`; thư mục `storage/app/public` không ghi được |
| Chuông không realtime | Bình thường nếu `BROADCAST_CONNECTION=log`; vẫn tải qua API |

---

## Chạy local (đối chiếu)

```bash
cd edueval-laravel
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Realtime local cần thêm: `php artisan reverb:start` (và `BROADCAST_CONNECTION=reverb`).
