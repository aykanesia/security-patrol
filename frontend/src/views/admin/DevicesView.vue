<script setup>
import { onMounted, reactive, ref } from 'vue'
import client, { apiError } from '../../api/client'

const devices = ref([])
const meta = ref({})
const loading = ref(false)
const error = ref('')
const filters = reactive({ status: '', search: '', page: 1 })

async function load() {
  loading.value = true
  try {
    const params = { page: filters.page, per_page: 15 }
    if (filters.status) params.status = filters.status
    if (filters.search) params.search = filters.search
    const { data } = await client.get('/admin/devices', { params })
    devices.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

async function toggleBlock(d) {
  const action = d.status === 'ACTIVE' ? 'block' : 'unblock'
  if (!confirm(`${action === 'block' ? 'Blokir' : 'Aktifkan kembali'} perangkat ${d.device_name || d.device_uuid}?`)) return
  try {
    await client.post(`/admin/devices/${d.id}/${action}`)
    load()
  } catch (e) {
    alert(apiError(e))
  }
}

function applyFilters() {
  filters.page = 1
  load()
}

function fmtTime(t) {
  if (!t) return '-'
  return t
}

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="sp-page-title mb-0"><i class="bi bi-phone me-2 text-primary"></i>Perangkat Android</h5>
    </div>

    <div class="card sp-card mb-3">
      <div class="card-body py-2">
        <div class="row g-2">
          <div class="col-md-4"><input v-model.trim="filters.search" class="form-control form-control-sm" placeholder="Cari nama / UUID perangkat" @keyup.enter="applyFilters" /></div>
          <div class="col-md-2">
            <select v-model="filters.status" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua Status</option>
              <option value="ACTIVE">ACTIVE</option>
              <option value="BLOCKED">BLOCKED</option>
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
        <div v-else-if="devices.length === 0" class="text-center text-muted py-4">Belum ada perangkat terdaftar</div>
        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Petugas</th><th>Perangkat</th><th>UUID</th><th>Platform</th><th>Terakhir Terlihat</th><th>Status</th><th class="text-end">Aksi</th></tr>
            </thead>
            <tbody>
              <tr v-for="d in devices" :key="d.id">
                <td class="fw-semibold">{{ d.officer }}</td>
                <td>{{ d.device_name || '-' }}</td>
                <td class="small text-muted text-break" style="max-width:180px">{{ d.device_uuid }}</td>
                <td class="small">{{ d.platform || '-' }} {{ d.app_version ? 'v' + d.app_version : '' }}</td>
                <td class="small">{{ fmtTime(d.last_seen_at) }}</td>
                <td><span class="badge" :class="d.status === 'ACTIVE' ? 'bg-success' : 'bg-danger'">{{ d.status }}</span></td>
                <td class="text-end">
                  <button class="btn btn-sm" :class="d.status === 'ACTIVE' ? 'btn-outline-danger' : 'btn-outline-success'" @click="toggleBlock(d)">
                    <i class="bi" :class="d.status === 'ACTIVE' ? 'bi-lock' : 'bi-unlock'"></i>
                    {{ d.status === 'ACTIVE' ? 'Blokir' : 'Aktifkan' }}
                  </button>
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
  </div>
</template>
