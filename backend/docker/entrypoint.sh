#!/bin/sh
# ============================================================
# Entrypoint container backend (Laravel) — dipakai oleh service
# `backend` (php-fpm) DAN `scheduler` (schedule:work).
#
# Aturan APP_KEY (hindari race antar service):
#   - APP_KEY di env  → langsung dipakai.
#   - storage/.app_key ada (volume bersama) → dimuat.
#   - Service utama (php-fpm) → generate + simpan ke file.
#   - Service lain → tunggu file sampai ada, baru muat.
# ============================================================
set -e

cd /var/www/html

IS_MAIN=false
[ "$1" = "php-fpm" ] && IS_MAIN=true

# --- 1) APP_KEY persist ---
if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE=/var/www/html/storage/.app_key
    if [ -f "$KEY_FILE" ]; then
        export APP_KEY="$(cat "$KEY_FILE")"
        echo "[entrypoint] APP_KEY dimuat dari storage/.app_key"
    elif [ "$IS_MAIN" = "true" ]; then
        export APP_KEY="base64:$(head -c 32 /dev/urandom | base64 -w0)"
        echo "$APP_KEY" > "$KEY_FILE"
        echo "[entrypoint] APP_KEY baru dibuat & disimpan ke storage/.app_key"
    else
        echo "[entrypoint] Menunggu APP_KEY dari service backend..."
        i=0
        until [ -f "$KEY_FILE" ]; do
            i=$((i + 1))
            if [ "$i" -ge 90 ]; then
                echo "[entrypoint] ERROR: storage/.app_key tidak muncul. Set APP_KEY di .env atau mulai service backend lebih dulu." >&2
                exit 1
            fi
            sleep 2
        done
        export APP_KEY="$(cat "$KEY_FILE")"
        echo "[entrypoint] APP_KEY dimuat dari storage/.app_key"
    fi
fi

# --- 2) struktur + permission storage ---
mkdir -p storage/app/public storage/framework/cache/data \
         storage/framework/sessions storage/framework/testing \
         storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# --- 3) tunggu database siap ---
echo "[entrypoint] Menunggu database siap..."
i=0
until php artisan migrate:status >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 90 ]; then
        echo "[entrypoint] Database tidak kunjung siap — keluar."
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] Database siap."

# --- 4) migrasi + seed hanya di service utama (php-fpm) ---
if [ "$IS_MAIN" = "true" ]; then
    echo "[entrypoint] Menjalankan migrasi..."
    php artisan migrate --force

    if [ ! -f storage/.seeded ]; then
        echo "[entrypoint] Seed data awal (sekali; reset dengan menghapus volume backenddata)..."
        php artisan db:seed --force
        touch storage/.seeded
    fi
else
    # service pendamping (scheduler): tunggu seed backend selesai
    i=0
    until [ -f storage/.seeded ]; do
        i=$((i + 1))
        if [ "$i" -ge 90 ]; then
            echo "[entrypoint] Seed tidak terdeteksi dalam batas waktu — lanjut apa adanya."
            break
        fi
        sleep 2
    done
fi

# --- 5) cache config (env sudah final) ---
php artisan config:cache 2>/dev/null || php artisan config:clear

exec "$@"
