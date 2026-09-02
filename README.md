# Sistem Monitoring Patroli Security Perumahan

Aplikasi monitoring patroli petugas keamanan perumahan berbasis **QR Code + GPS**.
Dibangun mengikuti PRD & Technical Design Document versi 1.0 (1 September 2026).

```
Petugas Android ──HTTPS──► Laravel REST API (Sanctum) ──► MySQL 8
                               ▲
Web Dashboard (Vue 3) ────────┘
```

## Fitur Utama

- **3 peran**: `super_admin`, `supervisor`, `security`
- **Manajemen master**: area, checkpoint (QR token random), rute (SEQUENTIAL/FLEXIBLE), jadwal mingguan + penugasan petugas, user, device
- **Alur patroli**: login → jadwal hari ini → mulai patroli → scan QR di tiap checkpoint → selesai
- **Validasi keamanan scan**: token QR unik + verifikasi GPS **Haversine** oleh backend + timestamp server resmi + cek urutan (sequential) + cegah duplikat
- **Idempotensi**: UUID per check-in — request ganda tidak pernah membuat record dobel
- **Offline sync** untuk Android (`POST /sync`, batch, status per item)
- **Web dashboard**: statistik, monitoring patroli aktif + peta Leaflet, histori & detail session, laporan harian/bulanan/attendance + export, audit log, notifikasi (termasuk peringatan patroli belum dimulai via scheduler)
- **Audit trail**: semua perubahan penting tercatat (siapa, apa, sebelum/sesudah, IP)

## Struktur Repo

```
security-patrol/
├── backend/    Laravel 12 (REST API v1, Sanctum, MySQL) + 33 test
├── frontend/   Vue 3 + Vite + Bootstrap 5 + Pinia + Leaflet
├── android/    Aplikasi Android Kotlin (Retrofit + QR ZXing + GPS + offline sync)
├── docs/       API_SPEC.md (untuk Android) + DEPLOYMENT.md (panduan server) + ANDROID_BUILD.md
└── scripts/    pembantu dev: smoke-test.sh, e2e-patrol-test.sh, mysql*.sh
```

## Stack (sesuai dokumen)

PHP 8.3+ · Laravel · REST API + Sanctum · MySQL 8+ · Nginx · PHP-FPM ·
Vue 3 · Vite · Bootstrap 5 · Axios · Vue Router · Pinia · Leaflet

## Cara Menjalankan (Dev)

Prasyarat: PHP 8.3+, Composer, MySQL 8+, Node 20+.

```bash
# 1) backend
cd backend
cp .env.example .env        # set koneksi MySQL (DB_DATABASE=security_patrol)
composer install
php artisan key:generate
php artisan migrate --seed  # buat tabel + data demo
php artisan serve           # http://127.0.0.1:8000

# 2) frontend (terminal lain)
cd frontend
npm install
npm run dev                 # http://127.0.0.1:5173 (proxy /api → :8000)
```

### Akun demo (ganti setelah login!)
| Username | Password | Role |
|---|---|---|
| `admin` | `password` | super_admin |
| `supervisor` | `password` | supervisor |
| `budi` / `andi` / `citra` | `password` | security |

### Test
```bash
cd backend && php artisan test        # 33 test, 117 assertions
bash ../scripts/smoke-test.sh        # uji HTTP dasar (server harus jalan)
bash ../scripts/e2e-patrol-test.sh   # alur patroli penuh via HTTP
```

## Aplikasi Android

Folder `android/` berisi project Android Studio (Kotlin) untuk petugas security:
login, jadwal hari ini, mulai patroli (GPS), scan QR checkpoint (ZXing),
progress real-time, selesai/batal patroli, riwayat + detail, dan **offline sync**
(WorkManager kirim batch ke `POST /sync` saat koneksi pulih).

Build & panduan lengkap: **[docs/ANDROID_BUILD.md](docs/ANDROID_BUILD.md)**.

## Deployment

Baca **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** — panduan lengkap Nginx +
PHP-FPM + MySQL + HTTPS + cron, termasuk konfigurasi server block siap salin.

## API untuk Android

Baca **[docs/API_SPEC.md](docs/API_SPEC.md)** — spesifikasi endpoint, format
response/error, alur integrasi, dan catatan keamanan (QR token, GPS server-side,
idempotensi UUID, offline sync).

## Catatan Implementasi

- Scan yang **gagal validasi tetap dicatat** (status `INVALID_*`, `sync_status=FAILED`)
  untuk kebutuhan audit — hanya check-in valid yang mengubah progress.
- `scanned_at` selalu **waktu server**; `device_timestamp` disimpan sebagai referensi device.
- Jarak GPS **dihitung backend** (Haversine) — nilai dari client tidak dipercaya.
- Master data memakai SoftDeletes; transaksi inti (check-in, session) dibungkus DB transaction.
