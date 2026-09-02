<script setup>
import { onMounted, reactive, ref } from 'vue'
import client, { apiError } from '../../api/client'

const schedules = ref([])
const routes = ref([])
const areas = ref([])
const users = ref([])
const meta = ref({})
const loading = ref(false)
const error = ref('')
const filters = reactive({ status: '', page: 1 })

const modal = reactive({ show: false, mode: 'create', id: null })
const form = reactive({
  route_id: '', name: '', day_of_week: '', start_time: '22:00', end_time: '23:00',
  grace_before_minutes: 15, grace_after_minutes: 15, status: 'ACTIVE', user_ids: [],
})
const saving = ref(false)

const dayLabels = { 0: 'Minggu', 1: 'Senin', 2: 'Selasa', 3: 'Rabu', 4: 'Kamis', 5: 'Jumat', 6: 'Sabtu' }
const dayOptions = Object.entries(dayLabels).map(([v, l]) => ({ v, l }))

async function load() {
  loading.value = true
  try {
    const params = { page: filters.page, per_page: 15 }
    if (filters.status) params.status = filters.status
    const { data } = await client.get('/admin/schedules', { params })
    schedules.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  const [routeRes, userRes, areaRes] = await Promise.all([
    client.get('/admin/routes', { params: { per_page: 100 } }),
    client.get('/admin/users', { params: { role: 'security', per_page: 100 } }),
    client.get('/admin/areas', { params: { all: true } }),
  ])
  routes.value = routeRes.data.data
  users.value = userRes.data.data
  areas.value = areaRes.data.data
}

function openCreate() {
  Object.assign(modal, { show: true, mode: 'create', id: null })
  Object.assign(form, {
    route_id: routes.value[0]?.id || '', name: '', day_of_week: '', start_time: '22:00', end_time: '23:00',
    grace_before_minutes: 15, grace_after_minutes: 15, status: 'ACTIVE', user_ids: [],
  })
}

async function openEdit(s) {
  try {
    const { data } = await client.get(`/admin/schedules/${s.id}`)
    const d = data.data
    Object.assign(modal, { show: true, mode: 'edit', id: s.id })
    Object.assign(form, {
      route_id: d.route_id, name: d.name,
      day_of_week: d.day_of_week === null || d.day_of_week === undefined ? '' : String(d.day_of_week),
      start_time: d.start_time.slice(0, 5), end_time: d.end_time.slice(0, 5),
      grace_before_minutes: d.grace_before_minutes, grace_after_minutes: d.grace_after_minutes,
      status: d.status, user_ids: d.assigned_users.map((u) => u.id),
    })
  } catch (e) {
    alert(apiError(e))
  }
}

function toggleUser(id) {
  const i = form.user_ids.indexOf(id)
  if (i >= 0) form.user_ids.splice(i, 1)
  else form.user_ids.push(id)
}

async function save() {
  if (!form.name || !form.route_id || !form.start_time || !form.end_time) {
    alert('Lengkapi nama, rute, dan jam patroli')
    return
  }
  saving.value = true
  try {
    const payload = {
      route_id: form.route_id,
      name: form.name,
      day_of_week: form.day_of_week === '' ? null : Number(form.day_of_week),
      start_time: form.start_time,
      end_time: form.end_time,
      grace_before_minutes: form.grace_before_minutes,
      grace_after_minutes: form.grace_after_minutes,
      status: form.status,
      user_ids: form.user_ids,
    }
    if (modal.mode === 'create') await client.post('/admin/schedules', payload)
    else await client.put(`/admin/schedules/${modal.id}`, payload)
    modal.show = false
    load()
  } catch (e) {
    alert(apiError(e))
  } finally {
    saving.value = false
  }
}

async function removeSchedule(s) {
  if (!confirm(`Hapus jadwal ${s.name}?`)) return
  try {
    await client.delete(`/admin/schedules/${s.id}`)
    load()
  } catch (e) {
    alert(apiError(e))
  }
}

const dayName = (d) => (d === null || d === undefined ? 'Setiap Hari' : dayLabels[d] || d)

function applyFilters() {
  filters.page = 1
  load()
}

onMounted(() => {
  loadOptions()
  load()
})
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="sp-page-title mb-0"><i class="bi bi-calendar-week me-2 text-primary"></i>Jadwal Patroli</h5>
      <button class="btn btn-sm btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Tambah Jadwal</button>
    </div>

    <div class="card sp-card mb-3">
      <div class="card-body py-2">
        <div class="row g-2">
          <div class="col-md-2">
            <select v-model="filters.status" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua Status</option>
              <option value="ACTIVE">ACTIVE</option>
              <option value="INACTIVE">INACTIVE</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="card sp-card">
      <div class="card-body p-0">
        <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        <div v-else-if="schedules.length === 0" class="text-center text-muted py-4">Tidak ada jadwal</div>
        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Nama Jadwal</th><th>Rute / Area</th><th>Hari</th><th>Jam</th><th>Petugas</th><th>Status</th><th class="text-end">Aksi</th></tr>
            </thead>
            <tbody>
              <tr v-for="s in schedules" :key="s.id">
                <td class="fw-semibold">{{ s.name }}</td>
                <td>
                  <div>{{ s.route }}</div>
                  <div class="small text-muted">{{ s.area }}</div>
                </td>
                <td class="small">{{ dayName(s.day_of_week) }}</td>
                <td class="small">{{ s.start_time }} – {{ s.end_time }}</td>
                <td>
                  <span class="badge bg-light text-dark border me-1" v-for="u in s.assigned_users" :key="u.id">{{ u.name }}</span>
                  <span v-if="s.assigned_users.length === 0" class="text-muted small">-</span>
                </td>
                <td><span class="badge" :class="s.status === 'ACTIVE' ? 'bg-success' : 'bg-secondary'">{{ s.status }}</span></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary me-1" @click="openEdit(s)"><i class="bi bi-pencil"></i></button>
                  <button class="btn btn-sm btn-outline-danger" @click="removeSchedule(s)"><i class="bi bi-trash"></i></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-if="meta.last_page > 1" class="card-footer d-flex justify-content-between">
        <span class="small text-muted">Hal {{ meta.current_page }} / {{ meta.last_page }}</span>
        <div class="btn-group">
          <button class="btn btn-sm btn-outline-primary" :disabled="meta.current_page <= 1" @click="filters.page--; load()">‹</button>
          <button class="btn btn-sm btn-outline-primary" :disabled="meta.current_page >= meta.last_page" @click="filters.page++; load()">›</button>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="modal.show" class="modal fade show d-block" tabindex="-1" @click.self="modal.show = false">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h6 class="modal-title">{{ modal.mode === 'create' ? 'Tambah Jadwal' : 'Edit Jadwal' }}</h6>
            <button class="btn-close" @click="modal.show = false"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label small">Nama Jadwal</label>
                <input v-model="form.name" class="form-control form-control-sm" placeholder="Patroli Malam 22:00" />
              </div>
              <div class="col-md-6">
                <label class="form-label small">Rute</label>
                <select v-model="form.route_id" class="form-select form-select-sm">
                  <option v-for="r in routes" :key="r.id" :value="r.id">{{ r.name }} ({{ r.area }})</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small">Hari (kosong = setiap hari)</label>
                <select v-model="form.day_of_week" class="form-select form-select-sm">
                  <option value="">Setiap Hari</option>
                  <option v-for="o in dayOptions" :key="o.v" :value="o.v">{{ o.l }}</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small">Jam Mulai</label>
                <input v-model="form.start_time" type="time" class="form-control form-control-sm" />
              </div>
              <div class="col-md-4">
                <label class="form-label small">Jam Selesai</label>
                <input v-model="form.end_time" type="time" class="form-control form-control-sm" />
              </div>
              <div class="col-md-3">
                <label class="form-label small">Grace Sebelum (menit)</label>
                <input v-model.number="form.grace_before_minutes" type="number" min="0" class="form-control form-control-sm" />
              </div>
              <div class="col-md-3">
                <label class="form-label small">Grace Sesudah (menit)</label>
                <input v-model.number="form.grace_after_minutes" type="number" min="0" class="form-control form-control-sm" />
              </div>
              <div class="col-md-3">
                <label class="form-label small">Status</label>
                <select v-model="form.status" class="form-select form-select-sm">
                  <option value="ACTIVE">ACTIVE</option>
                  <option value="INACTIVE">INACTIVE</option>
                </select>
              </div>
            </div>

            <div class="mt-3">
              <label class="form-label small fw-semibold">Petugas yang Ditugaskan</label>
              <div class="border rounded p-2" style="max-height:180px; overflow-y:auto">
                <div v-if="users.length === 0" class="small text-muted">Tidak ada petugas security. Buat dulu di menu Petugas.</div>
                <div v-for="u in users" :key="u.id" class="form-check">
                  <input class="form-check-input" type="checkbox" :id="'u' + u.id" :checked="form.user_ids.includes(u.id)" @change="toggleUser(u.id)" />
                  <label class="form-check-label small" :for="'u' + u.id">{{ u.name }} ({{ u.employee_code }})</label>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer py-2">
            <button class="btn btn-sm btn-secondary" @click="modal.show = false">Batal</button>
            <button class="btn btn-sm btn-primary" :disabled="saving" @click="save">Simpan</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="modal.show" class="modal-backdrop fade show"></div>
  </div>
</template>
