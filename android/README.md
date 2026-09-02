# Security Patrol — Aplikasi Android (Kotlin)

Aplikasi Android native untuk petugas **security** — consume REST API Laravel
(`/api/v1`) yang sama dengan web dashboard. QR scan + validasi GPS dilakukan
server-side; aplikasi punya mode **offline** yang menyinkronkan otomatis.

```
Petugas (HP Android) ──HTTPS──► Laravel REST API (Sanctum) ──► MySQL
```

## Fitur

- Login khusus role `security` (device_uuid otomatis per instalasi)
- Jadwal hari ini + tombol **Mulai Patroli** (lokasi GPS diambil dulu)
- Layar patroli berjalan: progress bar, daftar checkpoint, **scan QR** (ZXing),
  selesai (wajib semua checkpoint) / batal
- Riwayat patroli + detail check-in (waktu, jarak, status)
- **Offline sync**: scan saat tidak ada koneksi masuk antrian lokal, dikirim ke
  `POST /sync` oleh WorkManager begitu online (periodik 15 menit)

## Stack

Kotlin · ViewBinding · Retrofit + Gson · OkHttp (auth interceptor) ·
Material 3 · ViewPager2 · Coroutines · ZXing (`zxing-android-embedded`) ·
Google Play Services Location · WorkManager · minSdk 26 / targetSdk 35 · Java 17

## Struktur

```
android/
├── settings.gradle.kts / build.gradle.kts / gradle.properties
├── gradle/wrapper/               ← wrapper 8.9 (tinggal buka & sync)
└── app/
    ├── build.gradle.kts          ← SDK, dependency, API_BASE_URL
    └── src/main/
        ├── AndroidManifest.xml
        ├── java/com/securitypatrol/app/
        │   ├── data/remote/      ← Retrofit API, DTO, ApiClient
        │   ├── data/local/       ← SessionManager, OfflineQueue
        │   ├── data/sync/        ← SyncWorker (WorkManager)
        │   ├── ui/login|home|patrol/  ← activity & fragment
        │   └── util/LocationHelper.kt
        └── res/                  ← layout, drawable, string (id), tema
```

## Cara Build

> Environment dev asisten tidak punya Java/Android SDK → APK tidak bisa
> dicompile di sini. Source lengkap & teraudit — buka di Android Studio:

1. **File → Open** → pilih folder `android/` → tunggu Gradle sync.
2. Atur `API_BASE_URL` di `android/app/build.gradle.kts`:
   - debug default: `http://10.0.2.2:8000/api/v1` (emulator → backend lokal)
   - ganti ke IP server / domain untuk HP asli / produksi.
3. Klik **Run ▶** (emulator / HP via USB debugging).

Panduan detail + troubleshooting: **`docs/ANDROID_BUILD.md`** (root repo).

## Akun Demo

| Username | Password | Role |
|---|---|---|
| `budi` / `andi` / `citra` | `password` | security |

## API Reference

Spesifikasi endpoint & format error: **`docs/API_SPEC.md`** (root repo).
