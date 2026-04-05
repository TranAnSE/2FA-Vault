# 🐋 Hướng dẫn khởi động Docker Development Environment

## ✅ Đã hoàn thành

1. ✅ **Docker image đã build thành công** - `2fa-vault-app:latest`
2. ✅ **Dockerfile.dev đã được fix** - Thêm libxml2-dev, libxslt-dev
3. ✅ **Cấu hình ports tránh xung đột:**
   - Laravel API: `8088`
   - Vite Dev Server: `5174`
   - MySQL: `33066`
   - Redis: `6380`
   - phpMyAdmin: `8081`
   - MailHog SMTP: `1026`
   - MailHog Web: `8026`

## ⏳ Đang chờ

**Docker Desktop đang khởi động** - Thường mất 1-2 phút.

## 🚀 Các bước tiếp theo (sau khi Docker sẵn sàng)

### 1. Kiểm tra Docker đã sẵn sàng

```powershell
docker ps
```

Nếu thấy danh sách containers (hoặc rỗng) thì OK. Nếu gặp lỗi thì Docker chưa sẵn sàng.

### 2. Start tất cả containers

```powershell
cd D:\2FA-Vault
docker-compose -f docker-compose.dev.yml up -d
```

**Expected output:**
```
✔ Container 2fa-vault-dev-mysql      Started
✔ Container 2fa-vault-dev-redis      Started
✔ Container 2fa-vault-dev-mailhog    Started
✔ Container 2fa-vault-dev-app        Started
✔ Container 2fa-vault-dev-vite       Started
✔ Container 2fa-vault-dev-phpmyadmin Started
```

### 3. Kiểm tra containers đang chạy

```powershell
docker-compose -f docker-compose.dev.yml ps
```

### 4. Cài đặt dependencies

#### a. Composer dependencies (PHP)

```powershell
docker-compose -f docker-compose.dev.yml exec app composer install
```

#### b. NPM dependencies (Node.js)

```powershell
docker-compose -f docker-compose.dev.yml exec vite npm install
```

### 5. Tạo APP_KEY

```powershell
docker-compose -f docker-compose.dev.yml exec app php artisan key:generate
```

### 6. Chạy database migrations

```powershell
docker-compose -f docker-compose.dev.yml exec app php artisan migrate --force
```

### 7. Cài đặt Laravel Passport

```powershell
docker-compose -f docker-compose.dev.yml exec app php artisan passport:install --force
```

### 8. Kiểm tra ứng dụng

- **API Backend:** http://localhost:8088
- **Vite Dev Server:** http://localhost:5174
- **phpMyAdmin:** http://localhost:8081
- **MailHog:** http://localhost:8026

### 9. Chạy tests để kiểm tra

```powershell
# PHPUnit tests
docker-compose -f docker-compose.dev.yml exec app composer test

# Hoặc với coverage
docker-compose -f docker-compose.dev.yml exec app composer test-coverage
```

## 🔧 Commands hữu ích

### Xem logs

```powershell
# Tất cả services
docker-compose -f docker-compose.dev.yml logs -f

# Chỉ app (Laravel)
docker-compose -f docker-compose.dev.yml logs -f app

# Chỉ vite
docker-compose -f docker-compose.dev.yml logs -f vite
```

### Restart services

```powershell
# Restart tất cả
docker-compose -f docker-compose.dev.yml restart

# Restart service cụ thể
docker-compose -f docker-compose.dev.yml restart app
docker-compose -f docker-compose.dev.yml restart vite
```

### Stop/Start

```powershell
# Stop tất cả
docker-compose -f docker-compose.dev.yml stop

# Start lại
docker-compose -f docker-compose.dev.yml start

# Stop và xóa containers (giữ volumes)
docker-compose -f docker-compose.dev.yml down

# Stop và xóa tất cả (bao gồm volumes - CẢNH BÁO: mất data)
docker-compose -f docker-compose.dev.yml down -v
```

### Shell vào container

```powershell
# Vào app container
docker-compose -f docker-compose.dev.yml exec app bash

# Vào vite container  
docker-compose -f docker-compose.dev.yml exec vite sh
```

## ⚠️ Troubleshooting

### Port bị chiếm

Nếu gặp lỗi "port is already allocated":

```powershell
# Kiểm tra port nào đang bị chiếm (ví dụ 5174)
Get-NetTCPConnection -LocalPort 5174 | Select-Object LocalPort, OwningProcess, State

# Kill process (thay PID)
Stop-Process -Id <PID> -Force

# Restart containers
docker-compose -f docker-compose.dev.yml up -d
```

### Docker Desktop không khởi động

1. Mở Task Manager (Ctrl+Shift+Esc)
2. Tìm "Docker Desktop", click chuột phải → "End task"
3. Mở lại Docker Desktop từ Start Menu
4. Đợi icon Docker Desktop ở system tray chuyển sang màu xanh

### Containers không start

```powershell
# Xem logs để tìm lỗi
docker-compose -f docker-compose.dev.yml logs

# Rebuild nếu cần
docker-compose -f docker-compose.dev.yml build app
docker-compose -f docker-compose.dev.yml up -d
```

## 📝 Scripts tự động

Cũng có thể dùng script tự động:

```powershell
# Windows PowerShell
.\scripts\setup-dev.ps1

# Linux/Mac
bash scripts/setup-dev.sh
```

## 🎯 Next Steps sau khi setup xong

1. ✅ Chạy test baseline: `composer test`
2. 📊 Xem test coverage: `composer test-coverage`
3. 📖 Đọc testing plan: `docs/TESTING-PLAN.md`
4. 🧪 Bắt đầu viết tests theo Phase 1 (E2EE tests)

---

**Tài liệu liên quan:**
- `DEVELOPMENT.md` - Chi tiết workflow development
- `docs/TESTING-PLAN.md` - Kế hoạch testing 6 tuần
- `.github/copilot-instructions.md` - Hướng dẫn cho AI assistant
- `scripts/README.md` - Automation scripts
