<script setup>
import { onMounted, reactive, ref } from 'vue'
import client, { apiError } from '../../api/client'

const routes = ref([])
const areas = ref([])
const checkpoints = ref([])
const meta = ref({})
const loading = ref(false)
const error = ref('')
const filters = reactive({ area_id: '', search: '', page: 1 })

const modal = reactive({ show: false, mode: 'create', id: null })
const form = reactive({
  area_id: '', name: '', description: '', route_type: 'SEQUENTIAL', status: 'ACTIVE',
  checkpoint_rows: [], // {checkpoint_id, sequence, is_required}
})
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const params = { page: filters.page, per_page: 15 }
    if (filters.area_id) params.area_id = filters.area_id
    if (filters.search) params.search = filters.search
    const { data } = await client.get('/admin/routes', { params })
    routes.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

async function loadAreas() {
  const { data } = await client.get('/admin/areas', { params: { all: true } })
  areas.value = data.data
}

async function loadCheckpoints() {
  const { data } = await client.get('/admin/checkpoints', { params: { all: true } })
  checkpoints.value = data.data
}

function openCreate() {
  Object.assign(modal, { show: true, mode: 'create', id: null })
  Object.assign(form, {
    area_id: areas.value[0]?.id || '', name: '', description: '',
    route_type: 'SEQUENTIAL', status: 'ACTIVE', checkpoint_rows: [],
  })
  addRow()
}

async function openEdit(r) {
  try {
    const { data } = await client.get(`/admin/routes/${r.id}`)
    const detail = data.data
    Object.assign(modal, { show: true, mode: 'edit', id: r.id })
    Object.assign(form, {
      area_id: detail.area_id, name: detail.name, description: detail.description || '',
      route_type: detail.route_type, status: detail.status,
      checkpoint_rows: detail.checkpoints.map((c) => ({
        checkpoint_id: c.checkpoint_id, sequence: c.sequence, is_required: c.is_required,
      })),
    })
    if (form.checkpoint_rows.length === 0) addRow()
  } catch (e) {
    alert(apiError(e))
  }
}

function addRow() {
  const nextSeq = form.checkpoint_rows.length + 1
  form.checkpoint_rows.push({ checkpoint_id: '', sequence: nextSeq, is_required: true })
}

function removeRow(i) {
  form.checkpoint_rows.splice(i, 1)
  form.checkpoint_rows.forEach((r, idx) => (r.sequence = idx + 1))
}

function onAreaChange() {
  // filter available checkpoint options to those not yet picked
}

function availableCheckpoints(rowIdx) {
  const used = form.checkpoint_rows
    .map((r, i) => (i === rowIdx ? null : r.checkpoint_id))
    .filter(Boolean)
  return checkpoints.value.filter((c) => !used.includes(c.id) && c.area_id === form.area_id)
}

async function save() {
  if (!form.name || form.checkpoint_rows.some((r) => !r.checkpoint_id)) {
    alert('Lengkapi nama rute dan pilihan checkpoint')
    return
  }
  saving.value = true
  try {
    const payload = {
      area_id: form.area_id,
      name: form.name,
      description: form.description,
      route_type: form.route_type,
      status: form.status,
      checkpoints: form.checkpoint_rows.map((r) => ({
        checkpoint_id: r.checkpoint_id,
        sequence: r.sequence,
        is_required: r.is_required,
      })),
    }
    if (modal.mode === 'create') await client.post('/admin/routes', payload)
    else await client.put(`/admin/routes/${modal.id}`, payload)
    modal.show = false
    load()
  } catch (e) {
    alert(apiError(e))
  } finally {
    saving.value = false
  }
}

async function removeRoute(r) {
  if (!confirm(`Hapus rute ${r.name}?`)) return
  try {
    await client.delete(`/admin/routes/${r.id}`)
    load()
  } catch (e) {
    alert(apiError(e))
  }
}

const typeBadge = (t) => (t === 'SEQUENTIAL' ? 'bg-primary' : 'bg-info text-dark')

function applyFilters() {
  filters.page = 1
  load()
}

onMounted(() => {
  loadAreas()
  loadCheckpoints()
  load()
})
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="sp-page-title mb-0"><i class="bi bi-signpost-2 me-2 text-primary"></i>Rute Patroli</h5>
      <button class="btn btn-sm btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Tambah Rute</button>
    </div>

    <div class="card sp-card mb-3">
      <div class="card-body py-2">
        <div class="row g-2">
          <div class="col-md-4"><input v-model.trim="filters.search" class="form-control form-control-sm" placeholder="Cari nama rute" @keyup.enter="applyFilters" /></div>
          <div class="col-md-2">
            <select v-model="filters.area_id" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua Area</option>
              <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
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
        <div v-else-if="routes.length === 0" class="text-center text-muted py-4">Tidak ada rute</div>
        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Nama Rute</th><th>Area</th><th>Tipe</th><th>Checkpoint</th><th>Status</th><th class="text-end">Aksi</th></tr>
            </thead>
            <tbody>
              <tr v-for="r in routes" :key="r.id">
                <td class="fw-semibold">{{ r.name }}</td>
                <td class="small">{{ r.area }}</td>
                <td><span class="badge" :class="typeBadge(r.route_type)">{{ r.route_type }}</span></td>
                <td><span class="badge bg-light text-dark border">{{ r.checkpoints_count }} titik</span></td>
                <td><span class="badge" :class="r.status === 'ACTIVE' ? 'bg-success' : 'bg-secondary'">{{ r.status }}</span></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary me-1" @click="openEdit(r)"><i class="bi bi-pencil"></i></button>
                  <button class="btn btn-sm btn-outline-danger" @click="removeRoute(r)"><i class="bi bi-trash"></i></button>
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
            <h6 class="modal-title">{{ modal.mode === 'create' ? 'Tambah Rute' : 'Edit Rute' }}</h6>
            <button class="btn-close" @click="modal.show = false"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2 mb-3">
              <div class="col-md-6">
                <label class="form-label small">Nama Rute</label>
                <input v-model="form.name" class="form-control form-control-sm" placeholder="Rute Malam Cluster Mawar" />
              </div>
              <div class="col-md-3">
                <label class="form-label small">Area</label>
                <select v-model="form.area_id" class="form-select form-select-sm" @change="onAreaChange">
                  <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label small">Tipe Rute</label>
                <select v-model="form.route_type" class="form-select form-select-sm">
                  <option value="SEQUENTIAL">SEQUENTIAL (urut)</option>
                  <option value="FLEXIBLE">FLEXIBLE (bebas)</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small">Deskripsi</label>
                <input v-model="form.description" class="form-control form-control-sm" />
              </div>
            </div>

            <label class="form-label small fw-semibold">Susunan Checkpoint</label>
            <div class="border rounded p-2 mb-2">
              <div v-for="(row, i) in form.checkpoint_rows" :key="i" class="row g-2 align-items-center mb-2">
                <div class="col-1 text-muted small fw-bold">{{ row.sequence }}.</div>
                <div class="col-6">
                  <select v-model="row.checkpoint_id" class="form-select form-select-sm">
                    <option value="" disabled>Pilih checkpoint</option>
                    <option v-for="c in availableCheckpoints(i)" :key="c.id" :value="c.id">
                      {{ c.code }} — {{ c.name }}
                    </option>
                  </select>
                </div>
                <div class="col-3 form-check form-switch d-flex align-items-center mt-2">
                  <input v-model="row.is_required" class="form-check-input me-1" type="checkbox" id="req" />
                  <label class="form-check-label small" for="req">Wajib</label>
                </div>
                <div class="col-2 text-end">
                  <button class="btn btn-sm btn-outline-danger" @click="removeRow(i)"><i class="bi bi-x-lg"></i></button>
                </div>
              </div>
              <button class="btn btn-sm btn-outline-primary w-100" @click="addRow"><i class="bi bi-plus-lg me-1"></i>Tambah Checkpoint</button>
            </div>

            <div class="alert alert-info small mb-0">
              <i class="bi bi-info-circle me-1"></i>
              <strong>SEQUENTIAL</strong>: checkpoint harus dikunjungi berurutan. <strong>FLEXIBLE</strong>: bebas urutan, semua wajib tetap harus dikunjungi.
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
