<script setup>
import { onMounted, reactive, ref } from 'vue'
import client, { apiError } from '../../api/client'

const logs = ref([])
const actions = ref([])
const meta = ref({})
const loading = ref(false)
const error = ref('')
const filters = reactive({ action: '', search: '', from: '', to: '', page: 1 })

async function load() {
  loading.value = true
  try {
    const params = { page: filters.page, per_page: 20 }
    if (filters.action) params.action = filters.action
    if (filters.from) params.from = filters.from
    if (filters.to) params.to = filters.to
    if (filters.search) params.search = filters.search
    const { data } = await client.get('/admin/audit-logs', { params })
    logs.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

async function loadActions() {
  try {
    const { data } = await client.get('/admin/audit-logs/actions')
    actions.value = data.data
  } catch (e) { /* ignore */ }
}

const actionBadge = (a) => {
  if (a.startsWith('CREATE')) return 'bg-success'
  if (a.startsWith('UPDATE')) return 'bg-warning text-dark'
  if (a.startsWith('DELETE')) return 'bg-danger'
  if (a.includes('LOGIN')) return 'bg-info text-dark'
  if (a.includes('PATROL_START')) return 'bg-primary'
  if (a.includes('PATROL_COMPLETE')) return 'bg-primary'
  if (a.includes('PATROL_CANCEL') || a.includes('PATROL_INCOMPLETE')) return 'bg-secondary'
  if (a.includes('CHECKPOINT_SCAN')) return 'bg-dark'
  return 'bg-light text-dark border'
}

function applyFilters() {
  filters.page = 1
  load()
}

function fmtJson(v) {
  if (!v) return '-'
  try {
    return JSON.stringify(typeof v === 'string' ? JSON.parse(v) : v)
  } catch {
    return String(v)
  }
}

onMounted(() => {
  loadActions()
  load()
})
</script>

<template>
  <div>
    <h5 class="sp-page-title mb-3"><i class="bi bi-journal-check me-2 text-primary"></i>Audit Log</h5>

    <div class="card sp-card mb-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <select v-model="filters.action" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua Aksi</option>
              <option v-for="a in actions" :key="a" :value="a">{{ a }}</option>
            </select>
          </div>
          <div class="col-md-2"><input v-model="filters.from" type="date" class="form-control form-control-sm" @change="applyFilters" /></div>
          <div class="col-md-2"><input v-model="filters.to" type="date" class="form-control form-control-sm" @change="applyFilters" /></div>
          <div class="col-md-2"><button class="btn btn-sm btn-primary" @click="applyFilters"><i class="bi bi-search me-1"></i>Filter</button></div>
        </div>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="card sp-card">
      <div class="card-body p-0">
        <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        <div v-else-if="logs.length === 0" class="text-center text-muted py-4">Tidak ada log</div>
        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Entitas</th><th>Detail</th><th>IP</th></tr>
            </thead>
            <tbody>
              <tr v-for="l in logs" :key="l.id">
                <td class="small text-muted">{{ l.created_at }}</td>
                <td class="small">{{ l.user || '-' }}</td>
                <td><span class="badge" :class="actionBadge(l.action)">{{ l.action }}</span></td>
                <td class="small">
                  {{ l.entity_type ? l.entity_type.split('\\').pop() : '-' }}
                  <span v-if="l.entity_id" class="text-muted">#{{ l.entity_id }}</span>
                </td>
                <td class="small text-muted text-break" style="max-width: 320px">
                  <template v-if="l.new_data">{{ fmtJson(l.new_data) }}</template>
                  <template v-else-if="l.old_data">→ {{ fmtJson(l.old_data) }}</template>
                  <template v-else>-</template>
                </td>
                <td class="small text-muted">{{ l.ip_address || '-' }}</td>
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
