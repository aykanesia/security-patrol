# Rencana Transformasi SaaS — Security Patrol Monitoring System

> Status: **Blueprint / keputusan arsitektur** (belum ada implementasi).
> Tanggal: 2026-09-03
> Keputusan yang sudah diambil:
> 1. **Isolasi data**: Opsi A — satu database + satu codebase + satu deployment, tiap data diberi `tenant_id`.
> 2. **Identifikasi tenant di web**: satu domain + **kode tenant saat login** (tanpa subdomain di fase awal).
> 3. Android: satu APK untuk semua pelanggan (kode tenant + URL server di layar login).

---

## 1. Model Konsep

```
                    ┌──────────────────────────────────────────┐
                    │         PLATFORM (Kamu / Imam)            │
                    │   role: platform_admin                    │
                    │   Bisa: buat tenant, suspend, bantu user  │
                    └──────────────────┬───────────────────────┘
                                       │ mengelola
        ┌──────────────────────────────┼──────────────────────────────┐
        │                              ▼                              │
        │  ┌──────────────────────────────────────────────────────┐  │
        │  │  1 Aplikasi  •  1 Database  •  1 Domain  •  1 VPS    │  │
        │  └──────────────────────────────────────────────────────┘  │
        │            ▲                    ▲              ▲           │
        │            │ tenant_id=mawar    │ tenant_id=    │ tenant_id │
        │            │                    │ tajuragung    │ =…        │
        │   ┌────────┴────────┐   ┌───────┴───────┐  ┌───┴──────┐    │
        │   │ Perumahan Mawar │   │ Perumahan     │  │ Tenant C │    │
        │   │ admin+petugas   │   │ Tajuragung    │  │ …        │    │
        │   └─────────────────┘   └───────────────┘  └──────────┘    │
        └────────────────────────────────────────────────────────────┘
```

Setiap pelanggan = **satu tenant**. Data antar tenant **terpisah total** walau berada di database yang sama — dipisahkan oleh kolom `tenant_id` yang diisi otomatis dan disaring otomatis di setiap query (dijelaskan di §4).

---

## 2. Tabel Baru: `tenants`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| code | string(50) unique | Kode unik tenant, dipakai saat login, mis. `mawar`, `tajuragung` (huruf kecil, tanpa spasi) |
| name | string(150) | Nama resmi, mis. "Perumahan Mawar Indah" |
| address / city | string nullable | Profil opsional |
| phone / email | string nullable | Kontak penanggung jawab tenant |
| status | enum | `TRIAL` / `ACTIVE` / `SUSPENDED` (saat suspend: semua user tenant terkunci otomatis) |
| plan | string nullable | Nama paket sewa (`BASIC`, `PRO`, …) untuk fase billing nanti |
| trial_ends_at | datetime nullable | Akhir masa trial |
| expires_at | datetime nullable | Akhir masa sewa (opsional, fase billing) |
| max_users / max_checkpoints | int nullable | Batas pemakaian per paket |
| created_at / updated_at | timestamp | |

Contoh data:
- `mawar` → "Perumahan Mawar Indah", ACTIVE
- `tajuragung` → "Perumahan Tajuragung", ACTIVE

---

## 3. Perubahan Skema Tabel yang Ada

### 3.1 Prinsip
- Semua tabel **milik domain tenant** mendapat kolom `tenant_id` (unsignedBigInteger, index, foreign key ke `tenants.id`).
- Tabel **sistem/global** TIDAK diberi `tenant_id`: `roles`, `password_reset_tokens`, `sessions` (web session), dan tabel auth Sanctum.

### 3.2 Daftar tabel + kolom tenant_id

| Tabel | tenant_id | Catatan |
|---|---|---|
| users | ✅ wajib | `platform_admin` boleh NULL (milik platform). Tabel user juga dapat `tenant_id` index |
| areas | ✅ wajib | |
| checkpoints | ✅ wajib | |
| patrol_routes | ✅ wajib | |
| route_checkpoints | ✅ (via relasi) | Cukup diturunkan dari route/checkpoint; tanpa kolom langsung (pivot) |
| patrol_schedules | ✅ wajib | |
| patrol_schedule_assignments | (via relasi) | Pivot schedule↔user |
| devices | ✅ wajib | |
| patrol_sessions | ✅ wajib | |
| patrol_checkins | ✅ wajib | Sering di-query berdiri sendiri (riwayat patrol), kolom langsung memudahkan & mengamankan |
| notifications | ✅ wajib | |
| audit_logs | ✅ wajib | |

> Catatan implementasi: pivot (`route_checkpoints`, `patrol_schedule_assignments`) tidak perlu kolom `tenant_id` **jika** semua akses selalu lewat parent yang sudah ter-scope. Di fase uji isolasi, pola ini diverifikasi dengan test lintas tenant.

### 3.3 Kendala unique yang harus diubah (biar antar-tenant boleh sama)

Saat ini beberapa kolom unique **global**, padahal tenant berbeda boleh punya nilai yang sama:

| Tabel | Kolom | Kondisi sekarang | Menjadi |
|---|---|---|---|
| users | username | unique global | unique **(tenant_id, username)** |
| users | employee_code | unique global | unique **(tenant_id, employee_code)** |
| checkpoints | code | unique global | unique **(tenant_id, code)** |
| checkpoints | qr_token | unique global | Tetap global (token acak panjang, aman) |
| devices | device_uuid | unique global | unique **(tenant_id, device_uuid)** |
| patrol_sessions | session_code | unique global | unique **(tenant_id, session_code)** |
| patrol_sessions | uuid | unique global | Tetap (UUID acak) |
| patrol_checkins | uuid | unique global | Tetap (UUID acak) |
| tenants | code | — | unique global (memang harus unik) |

Kendala unique gabungan lain (`route_id+checkpoint_id`, `schedule_id+user_id`, dll.) **tidak berubah** — id global sudah unik sehingga kombinasi tetap aman.

### 3.4 Relasi yang harus diverifikasi
Foreign key antar tabel domain tetap sama; yang berubah hanya aksesnya harus melewati scope tenant (§4). Contoh relasi berantai yang wajib lolos uji: session → user → tenant, checkpoint → area → tenant, device → user → tenant.

---

## 4. Tembok Isolasi: Bagaimana Data Tidak Bisa Bocor

Tiga lapis pengaman, semuanya otomatis:

1. **Global Scope di Model** — setiap model domain punya scope bawaan yang selalu menambahkan `WHERE tenant_id = <tenant aktif>` di SEMUA query (baca, cari, update, hapus, relasi). Developer tidak perlu menulis filter manual; mustahil lupa.
   - Efek: `findOrFail(id)` milik tenant lain → **404** (bukan 403, supaya tidak membocorkan keberadaan data).

2. **Middleware Penentu Tenant** — setelah login/auth:
   - Web: tenant diambil dari user yang login (`user->tenant_id`).
   - (Fase lanjut, opsional) Subdomain: `mawar.app.com` → tenant `mawar` → lalu dicek bahwa user memang anggota tenant itu.
   - Semua request yang menyangkut domain tenant **wajib** lewat middleware ini. Route admin/platform global tidak boleh memakai scope tenant.

3. **Cek Status Tenant** — middleware kedua memblokir request jika tenant berstatus `SUSPENDED`/non-aktif → 403 "Akun organisasi anda tidak aktif" (kecuali `platform_admin`).

### Alur request singkat
```
Request masuk (web/Android)
  → auth: siapa user-nya (token Sanctum)
  → resolve tenant dari user (atau header/subdomain)
  → cek status tenant ACTIVE?
  → set "tenant aktif" utk sesi request ini
  → masuk controller — semua query otomatis ter-scope tenant
```

---

## 5. Autentikasi & Role

### 5.1 Login multi-tenant
Endpoint `POST /auth/login` bertambah satu field wajib: **`tenant_code`**.

```
Input : tenant_code + username + password (+ device_* utk Android)
Proses: cari tenant by code → cek status → cari user di DALAM tenant itu
        (username = "admin" boleh ada di Mawar & Tajuragung sekaligus)
        → verifikasi password → terbitkan token Sanctum
Output: token + data user + data tenant (nama, logo) utk tampilan web/Android
```

Token Sanctum yang terbit **terikat ke user → tenant**. Tidak ada cara user tenant lain memakai token ini.

### 5.2 Role

| Role | Lingkup | Fungsi |
|---|---|---|
| **platform_admin** *(baru)* | Platform (tidak punya tenant) | Kelola tenant, suspend/aktifkan, reset password, "masuk sebagai" tenant untuk bantuan |
| super_admin | Dalam 1 tenant | Seperti sekarang: kelola area/checkpoint/rute/jadwal/user/devices di tenant-nya |
| supervisor | Dalam 1 tenant | Monitoring + laporan tenant-nya |
| security | Dalam 1 tenant | Patroli via Android di tenant-nya |

Role `super_admin/supervisor/security` **tidak berubah** — hanya saja sekarang konteksnya selalu "di dalam tenant X". Tabel `roles` tetap global (satu set role dipakai semua tenant).

### 5.3 Middleware role
- `role:platform_admin` → khusus route `/platform/*`.
- `role:super_admin,supervisor,security` → route tenant seperti sekarang (otomatis ter-scope tenant).

---

## 6. Perubahan Backend (Ringkas)

| Area | Perubahan |
|---|---|
| Model | Tambah `BelongsTo Tenant` + Global Scope tenant di 10+ model domain |
| Migration baru | Buat `tenants`, tambah `tenant_id` + index + FK, ubah kendala unique (§3.3) |
| Seeder | Seeder tenant contoh + akun demo per tenant |
| AuthController | Login wajib `tenant_code`; response sertakan profil tenant |
| Middleware baru | `ResolveTenant` (dari user/header/subdomain) + `EnsureTenantActive` |
| Route baru | Grup `/platform/*`: tenants CRUD, suspend/aktifkan, reset password, ringkasan pemakaian |
| Service | PatrolService/ReportService/SyncService dsb. — semua query via model (ter-scope otomatis); pastikan tidak ada query `DB::table()`/raw yang melompati scope |
| Scheduler | Perintah terjadwal (mis. notifikasi patrol terlewat) berjalan **per tenant aktif** — loop tenant ACTIVE atau job membawa tenant_id |
| Audit | `audit_logs` ikut tenant; `platform_admin` punya jejak sendiri (tenant_id NULL) |

---

## 7. Perubahan Frontend Web

| Halaman | Perubahan |
|---|---|
| Login | Tambah input **"Kode Perumahan"** (contoh: `mawar`) → dikirim ke login |
| Setelah login | Tampilkan nama/logo tenant di sidebar & judul halaman (ambil dari response login) |
| Semua halaman tenant | Tidak berubah secara fitur — request otomatis membawa konteks tenant |
| Route guard | Tetap per role; halaman platform khusus `platform_admin` |
| Panel Platform (baru) | Daftar tenant + status, form tambah tenant, tombol suspend/aktifkan, reset password user, lihat jumlah user/checkpoint per tenant |

Catatan: SPA tetap **satu build** untuk semua tenant; tidak ada build per pelanggan.

---

## 8. Perubahan Android

| Bagian | Perubahan |
|---|---|
| Layar login | Tambah field **Kode Perumahan** + pengaturan **URL server** (biar satu APK bisa diarahkan ke server produksi) |
| SessionManager | Simpan tenant_code + nama tenant + server URL |
| ApiClient | Kirim `tenant_code` di login; semua request lain otomatis memakai token tenant |
| Tampilan | Header/ikon bisa menampilkan nama tenant (opsional) |

**Konsekuensi bagus**: APK cukup di-build **sekali**, dipakai semua pelanggan. Tidak ada APK khusus per perumahan.

---

## 9. Migrasi Data Saat Ini (Tenant Pertama)

Data yang sudah ada di deployment sekarang (area/checkpoint/rute/jadwal/user milikmu) menjadi **tenant pertama**:

1. Buat baris `tenants` (mis. code `demo` atau nama perumahan pertamamu) — status ACTIVE.
2. Isi `tenant_id` semua baris existing dengan tenant pertama tsb.
3. Baru setelah ter-backfill, ubah kolom jadi NOT NULL + pasang unique baru (§3.3).
4. Buat akun `platform_admin` untukmu (tidak terikat tenant).
5. Verifikasi: seluruh fungsi lama tetap jalan untuk tenant pertama, sebelum tenant kedua ditambahkan.

> ⚠️ Migrasi ini adalah titik paling sensitif. Wajib: backup database penuh dulu, uji di salinan, baru jalankan di produksi. (Prosedur rinci akan dibuat saat fase implementasi.)

---

## 10. Deployment

| Aspek | Fase awal (sekarang) | Naik kelas nanti (opsional) |
|---|---|---|
| Bentuk | Tetap 1 VPS + Docker compose (backend/frontend/scheduler/mysql) | Pisah queue worker + Redis |
| Database | 1 MySQL — backup 1 DB = semua tenant aman | Replica read-only utk laporan |
| SSL | 1 domain (`https://appkamu.com`) + Let's Encrypt | + wildcard jika nanti subdomain per tenant |
| Backup | `mysqldump` terjadwal + enkripsi; uji restore berkala | Backup/restore per-tenant utk layanan premium |
| Monitoring | Log + uptime sederhana | Per-tenant usage & kuota |

Alur on-boarding pelanggan baru (versi manual):
1. Kamu buka panel platform → isi nama + kode tenant + paket → **±1 menit**, tenant aktif.
2. Buatkan akun admin tenant (atau kirim undangan).
3. Pelanggan buka `appkamu.com`, isi kode tenant + username + password → langsung pakai.
4. Tagihan macet → klik **Suspend** → seluruh akses tenant terkunci, data tetap tersimpan aman.

---

## 11. Keamanan & Uji Isolasi (Wajib, Setiap Fase)

Test otomatis (PHPUnit) yang harus ada & hijau sebelum fase dianggap selesai:

| Skenario uji | Harapan |
|---|---|
| User tenant Mawar list area | Hanya area Mawar yang muncul |
| User Mawar akses area id milik Tajuragung (GET/PUT/DELETE) | **404** (bukan 403, tidak membocorkan eksistensi) |
| Username sama di 2 tenant, login dengan tenant_code masing-masing | Masing-masing sukses masuk ke tenant-nya |
| Login tenant_code salah / tenant SUSPENDED | Ditolak (403/422) |
| Token user Mawar dipakai akses API tenant lain | Ditolak |
| `platform_admin` | Bisa akses semua tenant (by design), tidak kena scope |
| Scheduler notifikasi | Hanya membidik user tenant aktif |
| Android login tenant A lalu akses data tenant B | Ditolak |

Selain itu: rate-limit login per tenant, audit log untuk aksi platform (buat/suspend tenant), dan backup terenkripsi terjadwal.

---

## 12. Roadmap Implementasi

| Fase | Isi | Keluar |
|---|---|---|
| **Fase 1 — Fondasi tenant** | Tabel `tenants`, kolom `tenant_id` + migrasi data existing, Global Scope, middleware tenant, login `tenant_code`, role `platform_admin` | Backend multi-tenant; data lama jadi tenant pertama; **uji isolasi lintas-tenant lulus** |
| **Fase 2 — Web & panel** | Form kode tenant di login web, tampilkan brand tenant, panel platform (CRUD tenant + suspend + reset password) | Kamu bisa kelola pelanggan dari web |
| **Fase 3 — Android** | Field kode tenant + URL server di login Android | Satu APK utk semua pelanggan |
| **Fase 4 — Operasional & billing** | Paket/kuota, expiry, payment gateway (Midtrans/Xendit), email, backup/restore per tenant, monitoring pemakaian | Siap jualan & skala |

Setiap fase **ditutup dengan uji isolasi 2 tenant** (Mawar & Tajuragung) sebelum lanjut ke fase berikutnya.

---

## 13. Keputusan yang Ditunda (Sengaja)

- **Subdomain per tenant** (`mawar.appkamu.com`) — bisa ditambahkan di fase mana pun tanpa mengubah aplikasi (cukup middleware resolve dari subdomain). Ditunda karena butuh wildcard DNS + SSL.
- **Database terpisah per tenant** (Opsi B) — hanya jika nanti ada pelanggan enterprise yang butuh isolasi fisik & mau bayar lebih. Desain fase 1 tidak menghalangi migrasi ini karena tenant_id sudah terstruktur rapi.
- **Billing otomatis** — ditunda ke Fase 4; mulai manual (transfer/WA) lebih dulu.

---

_Lampiran: dokumen ini hanya blueprint. Implementasi kode akan dilakukan per fase, dimulai dari Fase 1, dengan CLAUDE.md (Think Before Coding, Simplicity First, Surgical Changes, Goal-Driven Execution) sebagai panduan kerja._
