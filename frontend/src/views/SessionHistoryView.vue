<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import client, { apiError } from '../api/client'

const route = useRoute()
const router = useRouter()

const sessions = ref([])
const meta = ref({})
const loading = ref(false)
const error = ref('')
const selected = ref(null)

const filters = reactive({
  status: '',
  search: route.query.search || '',
  from: '',
  to: '',
  per_page: 15,
  page: 1,
})

const statusBadge = (s) => ({
  RUNNING: 'bg-primary',
  COMPLETED: 'bg-success',
  INCOMPLETE: 'bg-warning text-dark',
  CANCELLED: 'bg-secondary',
}[s] || 'bg-secondary')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = { page: filters.page, per_page: filters.per_page }
    if (filters.status) params.status = filters.status
    if (filters.from) params.from = filters.from
    if (filters.to) params.to = filters.to
    if (filters.search) params.search = filters.search

    const { data } = await client.get('/sessions', { params })
    sessions.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  filters.page = 1
  load()
}

function durLabel(sec) {
  if (!sec && sec !== 0) return '-'
  const m = Math.floor(sec / 60)
  const s = sec % 60
  return m > 0 ? `${m}m ${s}s` : `${s}s`
}

async function openDetail(session) {
  try {
    const { data } = await client.get(`/sessions/${session.id}`)
    selected.value = data.data
  } catch (e) {
    error.value = apiError(e)
  }
}

async function markIncomplete(session) {
  const reason = prompt('Alasan menandai patroli INCOMPLETE:', 'Checkpoint tidak dapat diakses')
  if (reason === null) return
  try {
    await client.post(`/sessions/${session.id}/incomplete`, { reason })
    load()
  } catch (e) {
    alert(apiError(e))
  }
}

watch(
  () => route.query.search,
  (v) => {
    filters.search = v || ''
    load()
  },
)

onMounted(load)
</script>

<template>
  <div>
    <h5 class="sp-page-title mb-3"><i class="bi bi-clock-history me-2 text-primary"></i>Histori Patroli</h5>

    <!-- Filters -->
    <div class="card sp-card mb-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label small mb-1">Cari (kode / petugas)</label>
            <input v-model.trim="filters.search" class="form-control form-control-sm" placeholder="PAT-2026... / nama" @keyup.enter="applyFilters" />
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-1">Status</label>
            <select v-model="filters.status" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua</option>
              <option value="RUNNING">RUNNING</option>
              <option value="COMPLETED">COMPLETED</option>
              <option value="INCOMPLETE">INCOMPLETE</option>
              <option value="CANCELLED">CANCELLED</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-1">Dari</label>
            <input v-model="filters.from" type="date" class="form-control form-control-sm" @change="applyFilters" />
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-1">Sampai</label>
            <input v-model="filters.to" type="date" class="form-control form-control-sm" @change="applyFilters" />
          </div>
          <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-sm btn-primary" @click="applyFilters"><i class="bi bi-search me-1"></i>Filter</button>
            <button class="btn btn-sm btn-outline-secondary" @click="Object.assign(filters, { status:'', from:'', to:'' }); applyFilters()">Reset</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="card sp-card">
      <div class="card-body p-0">
        <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        <div v-else-if="sessions.length === 0" class="text-center text-muted py-4">Tidak ada data patroli</div>
        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Session</th><th>Petugas</th><th>Rute</th><th>Mulai</th><th>Selesai</th><th>Durasi</th><th>Checkpoint</th><th>Status</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in sessions" :key="s.id">
                <td class="small text-muted">{{ s.session_code }}</td>
                <td>
                  <div class="fw-semibold">{{ s.officer.name }}</div>
                  <div class="small text-muted">{{ s.officer.employee_code }}</div>
                </td>
                <td><div>{{ s.route.name }}</div><div class="small text-muted">{{ s.route.area }}</div></td>
                <td class="small">{{ s.started_at }}</td>
                <td class="small">{{ s.completed_at || '-' }}</td>
                <td class="small">{{ durLabel(s.duration_seconds) }}</td>
                <td><span class="badge bg-light text-dark border">{{ s.completed_checkpoint }}/{{ s.total_checkpoint }}</span></td>
                <td><span class="badge" :class="statusBadge(s.status)">{{ s.status }}</span></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-secondary me-1" @click="openDetail(s)" title="Detail"><i class="bi bi-eye"></i></button>
                  <button v-if="s.status === 'RUNNING'" class="btn btn-sm btn-outline-warning" @click="markIncomplete(s)" title="Tandai incomplete"><i class="bi bi-flag"></i></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-if="meta.last_page > 1" class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">Hal {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} data)</span>
        <div class="btn-group">
          <button class="btn btn-sm btn-outline-primary" :disabled="meta.current_page <= 1" @click="filters.page--; load()">‹</button>
          <button class="btn btn-sm btn-outline-primary" :disabled="meta.current_page >= meta.last_page" @click="filters.page++; load()">›</button>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <div v-if="selected" class="modal fade show d-block" tabindex="-1" @click.self="selected = null">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h6 class="modal-title">Detail {{ selected.session_code }}</h6>
            <button class="btn-close" @click="selected = null"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2 small mb-3">
              <div class="col-md-4"><span class="text-muted">Petugas:</span> <strong>{{ selected.officer.name }}</strong></div>
              <div class="col-md-4"><span class="text-muted">Rute:</span> {{ selected.route.name }} ({{ selected.route.area }})</div>
              <div class="col-md-4"><span class="text-muted">Status:</span> <span class="badge" :class="statusBadge(selected.status)">{{ selected.status }}</span></div>
              <div class="col-md-4"><span class="text-muted">Mulai:</span> {{ selected.started_at }}</div>
              <div class="col-md-4"><span class="text-muted">Selesai:</span> {{ selected.completed_at || '-' }}</div>
              <div class="col-md-4"><span class="text-muted">Durasi:</span> {{ durLabel(selected.duration_seconds) }}</div>
            </div>
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-light"><tr><th>#</th><th>Checkpoint</th><th>Waktu Scan</th><th>GPS</th><th>Jarak</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="(c, i) in selected.checkins" :key="c.id">
                  <td>{{ i + 1 }}</td>
                  <td>{{ c.checkpoint.code }} — {{ c.checkpoint.name }}</td>
                  <td>{{ c.scanned_at }}</td>
                  <td class="small text-muted">{{ Number(c.latitude).toFixed(6) }}, {{ Number(c.longitude).toFixed(6) }}</td>
                  <td class="small">{{ c.distance_meter != null ? c.distance_meter + ' m' : '-' }}</td>
                  <td>
                    <span class="badge" :class="c.validation_status === 'VALID' ? 'bg-success' : 'bg-danger'">{{ c.validation_status }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div v-if="selected" class="modal-backdrop fade show"></div>
  </div>
</template>
