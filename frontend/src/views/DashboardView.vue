<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import client from '../api/client'

const router = useRouter()
const stats = ref(null)
const activePatrols = ref([])
const loading = ref(true)
let timer = null

const today = new Date().toISOString().slice(0, 10)

async function load() {
  try {
    const [statsRes, patrolRes] = await Promise.all([
      client.get('/dashboard/stats', { params: { date: today } }),
      client.get('/dashboard/active-patrols'),
    ])
    stats.value = statsRes.data.data
    activePatrols.value = patrolRes.data.data
  } catch (e) {
    /* handled by interceptor */
  } finally {
    loading.value = false
  }
}

function timeLabel(iso) {
  if (!iso) return '-'
  const d = new Date(iso)
  return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

onMounted(() => {
  load()
  timer = setInterval(load, 30000) // refresh every 30s
})
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <div v-if="loading" class="text-center py-5">
    <div class="spinner-border text-primary"></div>
  </div>

  <template v-else>
    <!-- Stat cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-4 col-xl-2">
        <div class="card sp-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-clipboard-check"></i></div>
            <div><div class="fs-4 fw-bold">{{ stats?.total_patrol ?? 0 }}</div><div class="small text-muted">Total Patrol</div></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="card sp-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle"></i></div>
            <div><div class="fs-4 fw-bold">{{ stats?.completed ?? 0 }}</div><div class="small text-muted">Completed</div></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="card sp-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-arrow-repeat"></i></div>
            <div><div class="fs-4 fw-bold">{{ stats?.running ?? 0 }}</div><div class="small text-muted">Running</div></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="card sp-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-x-circle"></i></div>
            <div><div class="fs-4 fw-bold">{{ stats?.incomplete ?? 0 }}</div><div class="small text-muted">Incomplete</div></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="card sp-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-person-badge"></i></div>
            <div><div class="fs-4 fw-bold">{{ stats?.active_officers ?? 0 }}</div><div class="small text-muted">Petugas Aktif</div></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="card sp-card h-100">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-geo-alt"></i></div>
            <div><div class="fs-4 fw-bold">{{ stats?.active_checkpoints ?? 0 }}</div><div class="small text-muted">Checkpoint</div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Active patrols -->
    <div class="card sp-card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-broadcast me-2 text-danger"></i>Patroli Aktif</span>
        <router-link to="/monitoring" class="btn btn-sm btn-outline-primary">Buka Live Map</router-link>
      </div>
      <div class="card-body p-0">
        <div v-if="activePatrols.length === 0" class="text-center text-muted py-4">
          <i class="bi bi-inbox fs-3 d-block mb-2"></i>Tidak ada patroli yang sedang berjalan
        </div>
        <div v-else class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Petugas</th><th>Rute</th><th>Progress</th><th>Mulai</th><th>Status</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in activePatrols" :key="p.session_code">
                <td>
                  <div class="fw-semibold">{{ p.officer.name }}</div>
                  <div class="small text-muted">{{ p.officer.employee_code }}</div>
                </td>
                <td>
                  <div>{{ p.route.name }}</div>
                  <div class="small text-muted">{{ p.route.area }}</div>
                </td>
                <td style="min-width:150px">
                  <div class="d-flex justify-content-between small mb-1">
                    <span>{{ p.progress.completed }}/{{ p.progress.total }}</span>
                    <span>{{ p.progress.percentage }}%</span>
                  </div>
                  <div class="progress" style="height:6px">
                    <div class="progress-bar" :class="p.progress.percentage === 100 ? 'bg-success' : 'bg-primary'" :style="{ width: p.progress.percentage + '%' }"></div>
                  </div>
                </td>
                <td class="small">{{ timeLabel(p.started_at) }}</td>
                <td><span class="badge bg-success-subtle text-success border border-success-subtle">RUNNING</span></td>
                <td class="text-end">
                  <router-link :to="'/sessions?search=' + p.session_code" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </template>
</template>
