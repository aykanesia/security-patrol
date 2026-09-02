# Panduan Docker — Security Patrol (Backend + Web)

Jalankan **backend Laravel + web dashboard Vue + MySQL + scheduler** dalam satu
perintah `docker compose`. Ini opsi paling cepat untuk produksi kecil
(perumahan / kantor) maupun untuk uji coba di mesin sendiri.

> Struktur Docker mengikuti **Opsi A**: semua file berada di repo yang sama,
> sehingga versi Docker selalu sinkron dengan kode aplikasi.

---

## 1. Isi File Docker

```
security-patrol/
├── docker-compose.yml            ← orkestrasi 4 service
├── .env.example                  ← template konfigurasi (salin ke .env)
├── backend/
│   ├── Dockerfile                ← PHP 8.3-FPM + composer + laravel
│   ├── .dockerignore
│   └── docker/
│       ├── entrypoint.sh         ← APP_KEY persist, tunggu DB, migrasi+seed, config:cache
│       └── php.ini               ← tuning produksi (opcache, memory, dll)
└── frontend/
    ├── Dockerfile                ← 2-stage: node build → nginx serve SPA
    ├── .dockerignore
    └── docker/
        └── nginx.conf            ← SPA Vue + proxy /api → backend:9000
```

### Service (docker-compose.yml)

| Service | Image | Peran |
|---|---|---|
| `mysql` | mysql:8.0 | database `security_patrol` (volume `mysql_data`) |
| `backend` | build `./backend` | PHP-FPM Laravel; entrypoint migrasi + seed |
| `scheduler` | build `./backend` | `php artisan schedule:work` (notifikasi patroli belum dimulai, tiap 5 mnt) |
| `frontend` | build `./frontend` | Nginx: serve `dist/` Vue + proxy `/api/*` → backend |

---

## 2. Cara Menjalankan

```bash
# 1) siapkan konfigurasi
cp .env.example .env
nano .env        # isi DB_PASSWORD & DB_ROOT_PASSWORD dengan password kuat

# 2) bangun & jalankan (pertama kali: build image ~3-5 menit)
docker compose up -d --build

# 3) lihat log (pastikan seed selesai)
docker compose logs -f backend

# 4) cek
curl http://localhost:8080/up          # → 200 (health Laravel)
curl http://localhost:8080/api/v1/me   # → 401 JSON (normal, belum login)
```

Buka **http://localhost:8080** di browser → halaman login web dashboard.

### Akun demo hasil seed (GANTI setelah masuk!)
| Username | Password | Role |
|---|---|---|
| `admin` | `password` | super_admin |
| `supervisor` | `password` | supervisor |
| `budi` / `andi` / `citra` | `password` | security |

> Seed hanya berjalan **sekali** (marker `storage/.seeded` di volume).
> Reset total: `docker compose down -v` lalu `up` lagi.

---

## 3. Menghubungkan Aplikasi Android

App Android menunjuk ke `API_BASE_URL` (lihat `android/app/build.gradle.kts`):

- **Emulator di mesin yang sama**: arahkan ke `http://10.0.2.2:8080/api/v1`
  (10.0.2.2 = localhost host; port sesuai `APP_PORT`).
- **HP asli (satu jaringan)**: `http://IP-SERVER:8080/api/v1`.
- **Produksi dengan domain**: pasang reverse-proxy HTTPS (mis. Caddy/Nginx di
  host) → `https://patroli.domain.com/api/v1`, lalu set `APP_URL` di `.env`.

> Aplikasi Android memakai HTTP cleartext saat debug — sudah diizinkan di
> manifest. Untuk produksi, pakai HTTPS (ganti `usesCleartextTraffic`).

---

## 4. Perintah Sehari-hari

```bash
docker compose ps                # status service
docker compose logs -f backend   # log Laravel
docker compose logs -f scheduler # log scheduler
docker compose restart backend   # restart satu service
docker compose down              # stop (data volume TETAP)
docker compose down -v           # stop + HAPUS volume (database & seed hilang)
docker compose up -d --build     # rebuild setelah update kode
```

### Update aplikasi ke versi baru
```bash
git pull                         # tarik kode terbaru
docker compose up -d --build     # rebuild image + restart otomatis
```

---

## 5. Kustomisasi Penting

| Hal | Lokasi | Catatan |
|---|---|---|
| Port web | `.env` → `APP_PORT` | default `8080` |
| Password DB | `.env` → `DB_PASSWORD`, `DB_ROOT_PASSWORD` | wajib diganti |
| URL publik | `.env` → `APP_URL` | untuk generate link |
| APP_KEY | `.env` → `APP_KEY` | kosongkan = otomatis (persist di volume) |
| Zona waktu | `docker-compose.yml` → `APP_TIMEZONE` | default `Asia/Jakarta` |
| Versi PHP | `backend/Dockerfile` | `php:8.3-fpm` |
| Versi Node | `frontend/Dockerfile` | `node:22-alpine` |

> Container tidak butuh `php artisan serve` — Nginx terhubung langsung ke
> PHP-FPM (`backend:9000`) seperti arsitektur produksi.

---

## 6. Troubleshooting

| Gejala | Solusi |
|---|---|
| `DB_PASSWORD` / `DB_ROOT_PASSWORD` error | isi dulu di `.env` (compose sengaja menolak password kosong) |
| Backend restart terus (crash loop) | `docker compose logs backend` — umumnya DB belum siap; entrypoint menunggu 2 mnt |
| Seed tidak jalan | cek log backend: pastikan volume `backend_storage` bersih (`down -v`) |
| Web 502 / API 404 | `docker compose logs frontend backend`; pastikan image ter-build ulang (`--build`) |
| `APP_KEY` beda antar service | pastikan volume `backend_storage` dipakai bersama (sudah default) |
| Mau ganti APP_KEY manual | `docker compose exec backend php artisan key:generate --show` → isi `APP_KEY` di `.env` → `up -d --build` |
