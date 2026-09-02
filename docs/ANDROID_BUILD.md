# Panduan Build Aplikasi Android (Kotlin Native)

Folder `android/` berisi project **Android Studio (Kotlin)** — aplikasi untuk petugas
security yang mengonsumsi REST API yang sama (Laravel). Di sini kamu buka di
Android Studio, bangun, lalu install ke HP.

> Environment pengembangan (sandbox) ini tidak punya Java/Android SDK, jadi
> APK tidak bisa di-compile di sini. Seluruh source sudah lengkap & teraudit —
> tinggal di-build di Android Studio di komputer kamu.

---

## 1. Prasyarat

- **Android Studio** (versi terbaru — Ladybug atau lebih baru) — unduh dari https://developer.android.com/studio
- Saat pertama buka project, Android Studio akan otomatis mengunduh:
  - JDK 17 (bawaan Android Studio)
  - Android SDK (compileSdk 35)
  - Gradle 8.9 (via wrapper yang sudah disertakan)
- Koneksi internet stabil (sekali unduh ~1-2 GB)

---

## 2. Buka Project

1. Buka Android Studio → **File → Open** → pilih folder `android/` (bukan `security-patrol/`).
2. Tunggu **Gradle Sync** selesai (akan otomatis; di kanan bawah ada progress).
3. Kalau diminta, setuju install komponen SDK yang kurang.

Struktur penting:
```
android/
├── settings.gradle.kts
├── build.gradle.kts
├── gradle.properties
├── gradlew / gradlew.bat
├── gradle/wrapper/gradle-wrapper.jar   ← sudah disertakan
└── app/
    ├── build.gradle.kts                ← versi SDK, dependency, API URL
    └── src/main/
        ├── AndroidManifest.xml
        ├── java/com/securitypatrol/app/   ← seluruh kode Kotlin
        └── res/                          ← layout, string, tema, icon
```

---

## 3. Set URL API (PENTING)

Base URL diatur di `android/app/build.gradle.kts`:

```kotlin
defaultConfig {
    // Release: URL produksi (domain server kamu)
    buildConfigField("String", "API_BASE_URL", "\"https://patroli.domain.com/api/v1\"")
}
buildTypes {
    debug {
        // Debug: emulator → 10.0.2.2 = localhost host
        buildConfigField("String", "API_BASE_URL", "\"http://10.0.2.2:8000/api/v1\"")
    }
}
```

- **Emulator + backend lokal (dev)**: tidak usah ubah apa-apa. Backend jalan di
  `127.0.0.1:8000` → otomatis ke `http://10.0.2.2:8000/api/v1`.
- **HP asli + backend di server**: ganti baris debug (atau build release) ke
  URL server. HP dan server harus satu jaringan (atau server sudah publik).
- **Catatan**: `usesCleartextTraffic=true` sudah diset agar HTTP lokal bisa
  dipakai saat dev. Untuk produksi gunakan HTTPS.

---

## 4. Build & Install

### Dari Android Studio
1. Colok HP Android (aktifkan **Developer options → USB debugging**), atau pakai emulator.
2. Klik **Run ▶** (Shift+F10) → pilih device → tunggu build selesai.
3. APK terinstall otomatis.

### APK manual (baris perintah)
```bash
cd android
./gradlew assembleDebug          # hasil: app/build/outputs/apk/debug/app-debug.apk
# atau release (butuh signing config — lihat §6)
./gradlew assembleRelease
```

---

## 5. Akun Masuk (Sesuai Seed Backend)

| Username | Password | Role |
|---|---|---|
| `budi` | `password` | security |
| `andi` | `password` | security |
| `citra` | `password` | security |

> Aplikasi hanya mengizinkan role **security** masuk (super_admin/supervisor
> pakai web dashboard). device_uuid dibuat otomatis per instalasi.

---

## 6. Persiapan Release (Play Store / distribusi APK)

1. **Ganti base URL** ke produksi (HTTPS).
2. Buat keystore & signing config di `app/build.gradle.kts`:
```kotlin
android {
    signingConfigs {
        create("release") {
            storeFile = file("release.keystore")
            storePassword = "***"
            keyAlias = "patrol"
            keyPassword = "***"
        }
    }
    buildTypes {
        release {
            signingConfig = signingConfigs.getByName("release")
            // disarankan juga aktifkan: isMinifyEnabled = true + proguard
        }
    }
}
```
3. `./gradlew assembleRelease`

---

## 7. Fitur yang Sudah Diimplementasikan

| Fitur | Lokasi (Kotlin) |
|---|---|
| Login (device_uuid otomatis, role check security) | `ui/login/LoginActivity.kt` |
| Jadwal hari ini + mulai patroli (dengan GPS) | `ui/home/ScheduleFragment.kt` |
| Riwayat patroli + detail | `ui/home/HistoryFragment.kt`, `ui/patrol/PatrolDetailActivity.kt` |
| Patroli berjalan: progress bar, daftar checkpoint, scan, selesai, batal | `ui/patrol/PatrolActivity.kt` |
| QR scanner (ZXing embedded) | `PatrolActivity.launchScanner()` |
| GPS (FusedLocationProvider) | `util/LocationHelper.kt` |
| DTO + Retrofit + error envelope backend | `data/remote/*` |
| Antrian offline + WorkManager sync berkala (15 mnt) | `data/local/OfflineQueue.kt`, `data/sync/SyncWorker.kt` |
| Enkripsi token & sesi (SharedPreferences) | `data/local/SessionManager.kt` |

### Alur scan offline
Saat tidak ada internet, hasil scan **disimpan lokal** (`OfflineQueue`) dan dikirim
otomatis ke `POST /sync` oleh WorkManager begitu koneksi pulih (atau tiap 15 menit).
Item yang sudah sukses/duplikat dihapus dari antrian; yang gagal bisnis tetap
ditampilkan sebagai error.

### Catatan QR
Backend menerima dua bentuk `scan_code`: **kode publik** (`CP001`) atau
**QR token penuh** (`PATROL:CP001:8f92a7c1...`). Aplikasi mengirim apa adanya
hasil baca QR — jadi pastikan QR yang ditempel di checkpoint berisi token penuh
(lihat endpoint admin `GET /admin/checkpoints/{id}/qr` untuk mengambilnya).

---

## 8. Troubleshooting

| Gejala | Solusi |
|---|---|
| Gradle sync error versi SDK | Android Studio → SDK Manager → install SDK Platform 35 |
| `Could not resolve ... material` | Pastikan koneksi internet saat sync pertama |
| Login sukses tapi langsung kembali | Pastikan akun ber-role `security` |
| Scan selalu "di luar radius" | Pastikan GPS HP akurat; radius checkpoint ≥ 25-30 m |
| Emulator tak dapat API | Backend harus jalan: `php artisan serve --host=0.0.0.0` (bukan hanya 127.0.0.1) |
| HP asli tak dapat API | Ganti `API_BASE_URL` ke IP LAN server (`http://192.168.x.x:8000/api/v1`) + pastikan firewall terbuka |
