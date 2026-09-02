# Panduan Deployment — Security Patrol Monitoring System

Target server: **Linux (Ubuntu/Debian 22.04+) + Nginx + PHP 8.3-FPM + MySQL 8 + Node.js (build frontend)**

> Stack sesuai dokumen Anda: PHP 8.3+, Laravel, REST API + Sanctum, MySQL 8+, Nginx,
> Vue 3 + Vite + Bootstrap 5 + Pinia + Leaflet.

---

## 1. Arsitektur di Server

```
Internet (HTTPS 443)
        │
   ┌────▼────┐
   │  NGINX  │  single server block
   └────┬────┘
        │
   ┌────▼────┐
   │  public/ │  Laravel (backend) — PHP-FPM
   └────┬────┘
        │
   ┌────▼────┐
   │  MySQL 8 │  database security_patrol
   └─────────┘

Web dashboard (Vue) di-serve dari folder frontend/dist
oleh Nginx sebagai static files (rute /).
API diproxy Nginx ke PHP-FPM (rute /api/*).
```

Jadi **satu domain** melayani dua-duanya: halaman web + API. App Android cukup
menunjuk ke `https://domain.com/api/v1`.

---

## 2. Persiapan Server (sekali jalan)

```bash
# update sistem
sudo apt update && sudo apt upgrade -y

# install dependensi
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-mysql php8.3-zip \
  php8.3-bcmath php8.3-intl php8.3-gd unzip git curl

# composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# node (untuk build frontend saja)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Cek versi:
```bash
php -v        # PHP 8.3.x
mysql --version
composer --version
node -v && npm -v
```

---

## 3. Siapkan Database

```sql
CREATE DATABASE security_patrol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'patrol'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON security_patrol.* TO 'patrol'@'localhost';
FLUSH PRIVILEGES;
```

---

## 4. Upload & Setup Backend

```bash
sudo mkdir -p /var/www/patroli
cd /var/www/patroli

# upload isi folder backend/ ke sini (scp/git clone), lalu:
sudo chown -R $USER:www-data backend storage bootstrap/cache 2>/dev/null || true

cd backend

# install dependensi php
composer install --no-dev --optimize-autoloader

# konfigurasi env
cp .env.example .env
nano .env
```

### Isi .env yang wajib diubah
```ini
APP_NAME="Security Patrol"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://patroli.domain.com
APP_LOCALE=id

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=security_patrol
DB_USERNAME=patrol
DB_PASSWORD=GANTI_PASSWORD_KUAT

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

Lalu:
```bash
# generate key + jalankan migrasi + seed data awal
php artisan key:generate
php artisan migrate --seed

# optimasi produksi
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

> Akun default hasil seed (WAJIB ganti password setelah login):
> - `admin` / `password` — super_admin
> - `supervisor` / `password` — supervisor
> - `budi` / `password` — security (demo)

---

## 5. Build Frontend

```bash
cd /var/www/patroli/frontend
npm ci            # install sesuai package-lock
npm run build     # hasil → dist/
```

> Jika environment server mengeset `NODE_ENV=production`, jalankan:
> `NODE_ENV=development npm run build` agar devDependencies (vite) ikut terinstall.

Hasil build di `frontend/dist/` — folder inilah yang di-serve Nginx sebagai web.

---

## 6. Konfigurasi Nginx

Buat file `/etc/nginx/sites-available/patroli`:

```nginx
server {
    listen 80;
    server_name patroli.domain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name patroli.domain.com;

    # ---- SSL (gunakan certbot) ----
    ssl_certificate     /etc/letsencrypt/live/patroli.domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/patroli.domain.com/privkey.pem;

    root /var/www/patroli/frontend/dist;
    index index.html;

    # gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript image/svg+xml;
    gzip_min_length 1024;

    client_max_body_size 10M;

    # ---------- API Laravel ----------
    location /api/ {
        root /var/www/patroli/backend/public;   # override root utk API
        try_files $uri /index.php?$query_string;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }
    }

    # health check
    location /up {
        root /var/www/patroli/backend/public;
        try_files /up /index.php?$query_string;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }
    }

    # ---------- File static frontend (Vue SPA) ----------
    location / {
        try_files $uri $uri/ /index.html;
    }

    # cache asset ber-hash (vite: index-xxxx.js/css)
    location ~* \.(js|css|woff2?|png|jpg|svg)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

> **Catatan penting**: karena root API di-override, pastikan path `backend/public`
> benar. Alternatif yang lebih rapi: buat symlink
> `ln -s /var/www/patroli/backend/public /var/www/patroli/api-public`
> lalu `root /var/www/patroli/api-public;` di blok `location /api/`.

Aktifkan + reload:
```bash
sudo ln -s /etc/nginx/sites-available/patroli /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# SSL otomatis
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d patroli.domain.com
```

---

## 7. Permission & Scheduler

```bash
# owner storage biar bisa ditulis php-fpm
sudo chown -R www-data:www-data /var/www/patroli/backend/storage
sudo chown -R www-data:www-data /var/www/patroli/backend/bootstrap/cache
```

### Cron untuk notifikasi terjadwal (patrol tidak dimulai, dsb.)
```bash
crontab -e -u www-data
```
```cron
* * * * * cd /var/www/patroli/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 8. Verifikasi

```bash
# API
curl https://patroli.domain.com/api/v1/me          # → 401 JSON (normal, belum ada token)
curl https://patroli.domain.com/up                 # → 200

# Login tes
curl -X POST https://patroli.domain.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'
# → dapat token

# Web
# buka https://patroli.domain.com di browser → halaman login
```

---

## 9. Checklist Go-Live

- [ ] `APP_DEBUG=false` & `APP_ENV=production`
- [ ] Password default (admin/supervisor/budi) diganti
- [ ] HTTPS aktif (certbot)
- [ ] DB user pakai password kuat, bukan default
- [ ] Firewall: buka 80/443 saja
- [ ] Backup rutin: `mysqldump security_patrol` + folder backend/storage
- [ ] Cron scheduler jalan
- [ ] (Opsional) Supervisor untuk queue kalau notifikasi async

---

## 10. Troubleshooting Singkat

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| Halaman web 404 | root frontend salah | cek `root` di blok `location /` |
| API 404/500 | root API salah | cek override `root` di `location /api/` |
| 419 Page Expired | session | pastikan `SESSION_DRIVER=database` & sudah migrate |
| 403 storage | permission | `chown -R www-data` storage |
| Login sukses tapi halaman kosong | build frontend lama | ulangi `npm run build` |
| Error `Route [login] not defined` | tidak relevan (sudah ditangani JSON 401) | pastikan versi terbaru kode |

---

## 11. Update Aplikasi (versi baru)

```bash
cd /var/www/patroli
# tarik kode terbaru (git pull / upload ulang)

cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

cd ../frontend
npm ci && NODE_ENV=development npm run build

sudo systemctl reload nginx
```
