<script setup>
import { onMounted, reactive, ref } from 'vue'
import client, { apiError } from '../../api/client'

const checkpoints = ref([])
const areas = ref([])
const meta = ref({})
const loading = ref(false)
const error = ref('')
const filters = reactive({ area_id: '', status: '', search: '', page: 1 })
const modal = reactive({ show: false, mode: 'create', id: null })
const form = reactive({
  area_id: '', code: '', name: '', description: '', latitude: '', longitude: '', radius_meter: 30, status: 'ACTIVE',
})
const saving = ref(false)
const qrTarget = ref(null)

async function load() {
  loading.value = true
  try {
    const params = { page: filters.page, per_page: 15 }
    if (filters.area_id) params.area_id = filters.area_id
    if (filters.status) params.status = filters.status
    if (filters.search) params.search = filters.search
    const { data } = await client.get('/admin/checkpoints', { params })
    checkpoints.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

async function loadAreas() {
  try {
    const { data } = await client.get('/admin/areas', { params: { all: true } })
    areas.value = data.data
  } catch (e) { /* ignore */ }
}

function openCreate() {
  Object.assign(modal, { show: true, mode: 'create', id: null })
  Object.assign(form, {
    area_id: areas.value[0]?.id || '', code: '', name: '', description: '',
    latitude: '', longitude: '', radius_meter: 30, status: 'ACTIVE',
  })
}

function openEdit(cp) {
  Object.assign(modal, { show: true, mode: 'edit', id: cp.id })
  Object.assign(form, {
    area_id: cp.area_id, code: cp.code, name: cp.name, description: cp.description || '',
    latitude: cp.latitude, longitude: cp.longitude, radius_meter: cp.radius_meter, status: cp.status,
  })
}

async function save() {
  saving.value = true
  try {
    const payload = { ...form }
    if (modal.mode === 'create') await client.post('/admin/checkpoints', payload)
    else await client.put(`/admin/checkpoints/${modal.id}`, payload)
    modal.show = false
    load()
  } catch (e) {
    alert(apiError(e))
  } finally {
    saving.value = false
  }
}

async function removeCp(cp) {
  if (!confirm(`Hapus checkpoint ${cp.code}?`)) return
  try {
    await client.delete(`/admin/checkpoints/${cp.id}`)
    load()
  } catch (e) {
    alert(apiError(e))
  }
}

async function showQr(cp) {
  try {
    const { data } = await client.get(`/admin/checkpoints/${cp.id}/qr`)
    qrTarget.value = data.data
  } catch (e) {
    alert(apiError(e))
  }
}

function qrImageUrl(token) {
  // use free QR generation service (works offline too: print view embeds token)
  return `https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=${encodeURIComponent(token)}`
}

function applyFilters() {
  filters.page = 1
  load()
}

onMounted(() => {
  loadAreas()
  load()
})
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="sp-page-title mb-0"><i class="bi bi-qr-code me-2 text-primary"></i>Checkpoint & QR Code</h5>
      <button class="btn btn-sm btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Tambah Checkpoint</button>
    </div>

    <div class="card sp-card mb-3">
      <div class="card-body py-2">
        <div class="row g-2">
          <div class="col-md-4"><input v-model.trim="filters.search" class="form-control form-control-sm" placeholder="Cari kode / nama" @keyup.enter="applyFilters" /></div>
          <div class="col-md-2">
            <select v-model="filters.area_id" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua Area</option>
              <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </div>
          <div class="col-md-2">
            <select v-model="filters.status" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua Status</option>
              <option value="ACTIVE">ACTIVE</option>
              <option value="INACTIVE">INACTIVE</option>
            </select>
          </div>
          <div class="col-md-1"><button class="btn btn-sm btn-primary" @click="applyFilters"><i class="bi bi-search"></i></button></div>
        </div>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="card sp-card">
      <div class="card-body p-0">
        <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        <div v-else-if="checkpoints.length === 0" class="text-center text-muted py-4">Tidak ada checkpoint</div>
        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Kode</th><th>Nama</th><th>Area</th><th>Koordinat</th><th>Radius</th><th>Status</th><th class="text-end">Aksi</th></tr>
            </thead>
            <tbody>
              <tr v-for="cp in checkpoints" :key="cp.id">
                <td class="fw-semibold">{{ cp.code }}</td>
                <td>{{ cp.name }}</td>
                <td class="small">{{ cp.area }}</td>
                <td class="small text-muted">{{ Number(cp.latitude).toFixed(6) }}, {{ Number(cp.longitude).toFixed(6) }}</td>
                <td class="small">{{ cp.radius_meter }} m</td>
                <td><span class="badge" :class="cp.status === 'ACTIVE' ? 'bg-success' : 'bg-secondary'">{{ cp.status }}</span></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-dark me-1" @click="showQr(cp)" title="Lihat QR"><i class="bi bi-qr-code"></i></button>
                  <button class="btn btn-sm btn-outline-primary me-1" @click="openEdit(cp)"><i class="bi bi-pencil"></i></button>
                  <button class="btn btn-sm btn-outline-danger" @click="removeCp(cp)"><i class="bi bi-trash"></i></button>
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

    <!-- create/edit modal -->
    <div v-if="modal.show" class="modal fade show d-block" tabindex="-1" @click.self="modal.show = false">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h6 class="modal-title">{{ modal.mode === 'create' ? 'Tambah Checkpoint' : 'Edit Checkpoint' }}</h6>
            <button class="btn-close" @click="modal.show = false"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2">
              <div class="col-md-4">
                <label class="form-label small">Area</label>
                <select v-model="form.area_id" class="form-select form-select-sm" required>
                  <option value="" disabled>Pilih area</option>
                  <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small">Kode (unik)</label>
                <input v-model="form.code" class="form-control form-control-sm text-uppercase" placeholder="CP001" required />
              </div>
              <div class="col-md-4">
                <label class="form-label small">Nama</label>
                <input v-model="form.name" class="form-control form-control-sm" placeholder="Pos Utama" required />
              </div>
              <div class="col-12">
                <label class="form-label small">Deskripsi</label>
                <input v-model="form.description" class="form-control form-control-sm" />
              </div>
              <div class="col-md-4">
                <label class="form-label small">Latitude</label>
                <input v-model="form.latitude" type="number" step="any" class="form-control form-control-sm" placeholder="-6.26000000" required />
              </div>
              <div class="col-md-4">
                <label class="form-label small">Longitude</label>
                <input v-model="form.longitude" type="number" step="any" class="form-control form-control-sm" placeholder="106.79000000" required />
              </div>
              <div class="col-md-4">
                <label class="form-label small">Radius Validasi (meter)</label>
                <input v-model.number="form.radius_meter" type="number" min="5" class="form-control form-control-sm" required />
              </div>
              <div class="col-md-4">
                <label class="form-label small">Status</label>
                <select v-model="form.status" class="form-select form-select-sm">
                  <option value="ACTIVE">ACTIVE</option>
                  <option value="INACTIVE">INACTIVE</option>
                </select>
              </div>
            </div>
            <div class="alert alert-info small mt-3 mb-0">
              <i class="bi bi-info-circle me-1"></i>QR Code dibuat otomatis & aman (token acak 32 karakter). Ganti radius menyesuaikan toleransi GPS lapangan.
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

    <!-- QR view modal -->
    <div v-if="qrTarget" class="modal fade show d-block" tabindex="-1" @click.self="qrTarget = null">
      <div class="modal-dialog modal-sm">
        <div class="modal-content text-center">
          <div class="modal-header py-2">
            <h6 class="modal-title mx-auto">QR — {{ qrTarget.code }}</h6>
          </div>
          <div class="modal-body">
            <img :src="qrImageUrl(qrTarget.qr_token)" alt="QR" class="img-fluid border rounded mb-2" style="max-width: 220px" />
            <div class="fw-semibold">{{ qrTarget.name }}</div>
            <div class="small text-muted text-break">{{ qrTarget.qr_token }}</div>
          </div>
          <div class="modal-footer py-2 justify-content-center">
            <a class="btn btn-sm btn-primary" :href="qrImageUrl(qrTarget.qr_token)" download="qr-{{ qrTarget.code }}.png" @click="qrTarget = null">
              <i class="bi bi-download me-1"></i>Cetak QR
            </a>
          </div>
        </div>
      </div>
    </div>
    <div v-if="qrTarget" class="modal-backdrop fade show"></div>
  </div>
</template>
