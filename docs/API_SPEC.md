# API Specification — Security Patrol Monitoring System

Base URL (production): `https://patroli.domain.com/api/v1`
Base URL (dev): `http://127.0.0.1:8000/api/v1`

Seluruh endpoint memakai **HTTPS** dan format JSON. Auth memakai **Laravel Sanctum** (Bearer token).

---

## 1. Standar Response

### Sukses — HTTP 200/201
```json
{
  "success": true,
  "message": "Sukses",
  "data": {},
  "meta": {}
}
```

### Error — HTTP 400/401/403/404/422
```json
{
  "success": false,
  "message": "Anda berada di luar radius checkpoint",
  "error_code": "INVALID_LOCATION",
  "data": {
    "distance_meter": 87.4,
    "allowed_radius": 30
  }
}
```

### Validasi Laravel — HTTP 422
```json
{
  "message": "The uuid field must be a valid UUID.",
  "errors": { "uuid": ["The uuid field must be a valid UUID."] }
}
```

### Daftar error_code penting
| error_code | HTTP | Arti |
|---|---|---|
| `UNAUTHENTICATED` | 401 | Token tidak ada/tidak valid |
| `FORBIDDEN` | 403 | Role tidak punya akses |
| `INVALID_LOCATION` | 422 | GPS di luar radius checkpoint |
| `INVALID_CHECKPOINT` | 422 | QR/kode tidak dikenali |
| `INVALID_SESSION` | 403/422 | Session bukan milik user / tidak RUNNING |
| `INVALID_SEQUENCE` | 422 | Urutan checkpoint salah (rute SEQUENTIAL) |
| `DUPLICATE_CHECKIN` | 422 | Checkpoint sudah discan valid |
| `ALREADY_PROCESSED` | 200 | UUID sama sudah diproses (idempotensi) |
| `CHECKPOINT_INCOMPLETE` | 422 | Complete ditolak, checkpoint belum lengkap |
| `SESSION_ALREADY_RUNNING` | 422 | User masih punya patroli berjalan |
| `SCHEDULE_TOO_EARLY` / `SCHEDULE_TOO_LATE` | 422 | Di luar window jadwal (grace) |
| `DEVICE_REQUIRED` / `DEVICE_BLOCKED` / `DEVICE_OWNED` | 422/403 | Validasi device |

---

## 2. Autentikasi

### POST /auth/login
Body:
```json
{
  "username": "budi",
  "password": "password",
  "device_uuid": "DEVICE-UUID",     // WAJIB untuk role security
  "device_name": "Samsung A54",     // opsional
  "platform": "android",            // opsional, default "android"
  "app_version": "1.0.0"            // opsional
}
```
Response:
```json
{
  "success": true,
  "message": "Login berhasil",
  "data": {
    "token": "1|abc...",
    "user": { "id": 3, "name": "Budi Santoso", "role": "security" }
  }
}
```
> Jika `device_uuid` sudah terdaftar untuk user lain → `DEVICE_OWNED` (403).

### POST /auth/logout — Auth required
Hapus token aktif.

### GET /me — Auth required
Profil user: `{ id, name, username, employee_code, role, status }`.

---

## 3. Patroli (role: security)

Header auth di semua request:
```
Authorization: Bearer {TOKEN}
Accept: application/json
```

### GET /patrol/schedules/today
Jadwal yang berlaku hari ini untuk user login.
```json
{
  "success": true,
  "data": [
    {
      "id": 10,
      "name": "Patroli Malam",
      "day_of_week": null,
      "start_time": "22:00:00",
      "end_time": "23:00:00",
      "grace_before_minutes": 15,
      "grace_after_minutes": 15,
      "route": { "id": 4, "name": "Rute Malam 1", "route_type": "SEQUENTIAL", "area": "Cluster Mawar", "total_checkpoint": 5 },
      "assigned": true
    }
  ]
}
```

### POST /patrol/start
```json
{
  "schedule_id": 10,
  "latitude": -6.26000000,
  "longitude": 106.79000000,
  "device_uuid": "DEVICE-UUID"
}
```
Response `data`: `session_code`, `status: RUNNING`, `started_at`, `total_checkpoint`, `completed_checkpoint: 0`.
Validasi backend: user ACTIVE + role security, schedule ACTIVE, window waktu (grace before/after), user terdaftar di schedule, tidak ada session RUNNING lain, device valid, rute punya checkpoint.

### GET /patrol/current
Session RUNNING user + daftar checkpoint berstatus `COMPLETED`/`PENDING`.
```json
{
  "success": true,
  "data": {
    "session": { "id": 1, "session_code": "PAT-20260902-000002", "status": "RUNNING", "started_at": "...", "total_checkpoint": 5, "completed_checkpoint": 3 },
    "route": { "id": 1, "name": "...", "route_type": "SEQUENTIAL" },
    "schedule": { "id": 10, "name": "..." },
    "checkpoints": [
      { "id": 1, "code": "CP001", "name": "Pos Utama", "sequence": 1, "is_required": true, "status": "COMPLETED", "scanned_at": "..." },
      { "id": 2, "code": "CP002", "name": "Blok A", "sequence": 2, "is_required": true, "status": "PENDING" }
    ],
    "progress_percentage": 60
  }
}
```
Jika tidak ada session RUNNING → `data: null`.

### POST /patrol/checkpoint/scan
```json
{
  "session_code": "PAT-20260902-000002",
  "scan_code": "PATROL:CP001:8f92a7c1...",   // QR token ATAU kode publik "CP001"
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "latitude": -6.26000100,
  "longitude": 106.79000100,
  "gps_accuracy": 8.5,                       // opsional
  "device_timestamp": "2026-09-02 10:00:10", // opsional (waktu device)
  "device_uuid": "DEVICE-UUID"               // opsional
}
```
Response sukses:
```json
{
  "success": true,
  "message": "Checkpoint berhasil",
  "data": {
    "checkpoint": { "code": "CP001", "name": "Pos Utama" },
    "scanned_at": "2026-09-02 10:00:11",
    "distance_meter": 2.3,
    "validation_status": "VALID",
    "progress": { "completed": 1, "total": 5, "percentage": 20 }
  }
}
```
Catatan penting:
- **`scanned_at` = waktu server** (timestamp resmi). `device_timestamp` hanya disimpan sebagai referensi.
- **Jarak dihitung backend (Haversine)** — jangan percaya jarak dari client.
- **Idempoten**: kirim ulang `uuid` sama → `ALREADY_PROCESSED` (HTTP 200), tidak pernah dobel insert.
- Scan gagal (GPS/urutan) **tetap tersimpan** sebagai baris `INVALID_*` dengan `sync_status=FAILED` — berguna untuk audit. Scan **valid** yang dihitung ke progress.

Error yang mungkin: `INVALID_LOCATION` (data: distance vs radius), `INVALID_SEQUENCE` (data: `required_checkpoint`), `DUPLICATE_CHECKIN`, `INVALID_CHECKPOINT`, `INVALID_SESSION`, `SESSION_NOT_RUNNING`.

### POST /patrol/complete
```json
{
  "session_code": "PAT-20260902-000002",
  "latitude": -6.26160000,
  "longitude": 106.79090000
}
```
Response:
```json
{
  "success": true,
  "data": {
    "status": "COMPLETED",
    "started_at": "...",
    "completed_at": "...",
    "duration_seconds": 2509,
    "checkpoint_completed": 5,
    "checkpoint_total": 5
  }
}
```
Jika checkpoint wajib belum lengkap → `CHECKPOINT_INCOMPLETE` (422).

### POST /patrol/cancel
```json
{ "session_code": "...", "reason": "opsional" }
```
Mengubah session RUNNING → CANCELLED.

### GET /patrol/history
Histori session user (pagination). Filter query: `?date=YYYY-MM-DD&status=COMPLETED&per_page=15`.

### GET /patrol/detail/{sessionCode}
Detail session + daftar check-in (waktu, GPS, jarak, status).

---

## 4. Offline Sync (role: security)

### POST /sync
Kirim batch check-in offline. Idempoten per UUID.
```json
{
  "items": [
    {
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "session_code": "PAT-20260902-000002",
      "checkpoint_code": "CP001",
      "scan_code": "PATROL:CP001:8f92a7c1...",
      "latitude": -6.26000100,
      "longitude": 106.79000100,
      "gps_accuracy": 8.5,
      "device_timestamp": "2026-09-02 10:00:10"
    }
  ]
}
```
Response:
```json
{
  "success": true,
  "data": {
    "processed": 3,
    "success": 2,
    "duplicate": 1,
    "failed": 0,
    "items": [
      { "uuid": "...", "status": "VALID" },
      { "uuid": "...", "status": "ALREADY_PROCESSED" }
    ]
  }
}
```
> Alur offline Android yang disarankan: scan tersimpan lokal (SQLite/Room) dengan UUID dibuat client → saat online, kirim batch → hapus item yang `success`/`duplicate`, tahan yang `failed` (invalid) untuk ditampilkan ke petugas.

---

## 5. Dashboard & Report (role: super_admin, supervisor)

| Endpoint | Deskripsi |
|---|---|
| `GET /dashboard/stats` | Kartu statistik: patrol hari ini (total/completed/running/incomplete), petugas aktif, checkpoint |
| `GET /dashboard/active-patrols` | Patroli yang sedang RUNNING + progress |
| `GET /dashboard/officer-positions` | Posisi petugas aktif (lat/lon terakhir) untuk peta Leaflet |
| `GET /sessions` | Histori semua session (filter: date, user, route, status) |
| `GET /sessions/{id}` | Detail session + check-in |
| `POST /sessions/{id}/incomplete` | Tandai session INCOMPLETE + alasan (kewenangan supervisor) |
| `GET /reports/daily` | Laporan harian |
| `GET /reports/monthly` | Laporan bulanan per petugas |
| `GET /reports/attendance` | Kehadiran patrol per petugas (compliance %) |
| `GET /reports/export/daily?date=YYYY-MM-DD` | Export (CSV/Excel) |
| `GET /reports/export/range?from=...&to=...` | Export rentang |
| `GET /notifications` | Daftar notifikasi user |
| `POST /notifications/{id}/read` | Tandai dibaca |
| `POST /notifications/read-all` | Tandai semua dibaca |

---

## 6. Admin CRUD (role: super_admin)

Prefix `admin/`:
- `users` (GET list, POST, GET {id}, PUT, DELETE) — payload role_id, employee_code, name, username, password, phone, status
- `roles` (GET) — daftar role
- `areas` (CRUD) — name, description, status
- `checkpoints` (CRUD) — area_id, code, name, latitude, longitude, radius_meter, status. `qr_token` dibuat otomatis (`PATROL:{CODE}:{random}`) saat create; **jangan diubah manual**
- `checkpoints/{id}/qr` (GET) — ambil QR token
- `routes` (CRUD) — area_id, name, route_type (SEQUENTIAL/FLEXIBLE), status; payload berisi `checkpoint_ids` (array) untuk relasi route_checkpoints
- `schedules` (CRUD) — route_id, name, day_of_week (0-6/null=setiap hari), start_time, end_time, grace, status, `user_ids[]`
- `devices` (GET list, POST {id}/block, POST {id}/unblock)
- `audit-logs` (GET, filter entity/action) — semua perubahan penting tercatat (siapa, apa, before/after JSON)

Semua master data (users/areas/checkpoints/routes) memakai **SoftDeletes**.

---

## 7. Alur Integrasi Android (ringkas)

1. **Login** → simpan token + user. Kirim `device_uuid` unik per instalasi (UUID).
2. **Jadwal** → `GET /patrol/schedules/today` → tampilkan + tombol "Mulai Patroli".
3. **Start** → ambil GPS → `POST /patrol/start` → simpan `session_code`.
4. **Scan** → baca QR (token `PATROL:*`), ambil GPS, generate UUID v4 → `POST /patrol/checkpoint/scan`.
5. **Offline** → kalau gagal jaringan, simpan lokal + `POST /sync` nanti.
6. **Selesai** → `GET /patrol/current` untuk cek progress → `POST /patrol/complete`.
7. **Riwayat** → `GET /patrol/history`.

### Catatan keamanan
- QR token random & unik — tidak berisi data sensitif, sulit ditebak.
- GPS diverifikasi backend (Haversine) — manipulasi client tidak berguna.
- Timestamp resmi = server clock.
- Token Sanctum: gunakan HTTPS; jangan hardcode di APK.
