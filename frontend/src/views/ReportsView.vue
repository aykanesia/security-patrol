<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import client, { apiError } from '../api/client'

const tab = ref('daily') // daily | monthly | attendance
const loading = ref(false)
const error = ref('')

const today = new Date()
const daily = reactive({ date: today.toISOString().slice(0, 10) })
const monthly = reactive({ year: today.getFullYear(), month: today.getMonth() + 1 })
const attendance = reactive({
  from: new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10),
  to: today.toISOString().slice(0, 10),
})

const data = ref(null)
const monthName = computed(() => {
  const m = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
  return m[(monthly.month || 1) - 1]
})

const monthOptions = Array.from({ length: 12 }, (_, i) => i + 1)
const yearOptions = Array.from({ length: 5 }, (_, i) => today.getFullYear() - i)

async function load() {
  loading.value = true
  error.value = ''
  try {
    let url = ''
    let params = {}
    if (tab.value === 'daily') {
      url = '/reports/daily'
      params = { date: daily.date }
    } else if (tab.value === 'monthly') {
      url = '/reports/monthly'
      params = { year: monthly.year, month: monthly.month }
    } else {
      url = '/reports/attendance'
      params = { from: attendance.from, to: attendance.to }
    }
    const { data: res } = await client.get(url, { params })
    data.value = res.data
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

function exportCsv() {
  let url = ''
  if (tab.value === 'daily') {
    url = `/reports/export/daily?date=${daily.date}`
  } else if (tab.value === 'attendance') {
    url = `/reports/export/range?from=${attendance.from}&to=${attendance.to}`
  } else {
    // monthly → export last 30 days ending today (approximation via range of that month)
    const from = `${monthly.year}-${String(monthly.month).padStart(2, '0')}-01`
    url = `/reports/export/range?from=${from}&to=${today.toISOString().slice(0, 10)}`
  }
  downloadWithToken(url)
}

// Unduh file dengan menyertakan token (window.open tidak bisa bawa header
// Authorization, sehingga request export selalu ditolak 401/500 oleh backend).
async function downloadWithToken(path) {
  try {
    const token = localStorage.getItem('sp_token')
    const res = await fetch(`/api/v1${path}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      alert(body.message || `Export gagal (HTTP ${res.status})`)
      return
    }
    const blob = await res.blob()
    const disposition = res.headers.get('Content-Disposition') || ''
    const m = disposition.match(/filename\*?=(?:UTF-8''|"?)([^";]+)/i)
    const filename = m ? m[1] : 'laporan.csv'
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = decodeURIComponent(filename)
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(link.href)
  } catch (e) {
    alert('Tidak dapat mengunduh file. Coba lagi.')
  }
}

function durLabel(sec) {
  if (!sec) return '0m'
  const m = Math.floor(sec / 60)
  return m > 60 ? `${Math.floor(m / 60)}j ${m % 60}m` : `${m}m`
}

function statCard(label, value, icon, color) {
  return { label, value, icon, color }
}

const dailyCards = computed(() => {
  if (!data.value) return []
  const d = data.value
  return [
    statCard('Total Patrol', d.total_patrol, 'bi-clipboard-check', 'primary'),
    statCard('Completed', d.completed, 'bi-check-circle', 'success'),
    statCard('Incomplete', d.incomplete, 'bi-x-circle', 'danger'),
    statCard('Cancelled', d.cancelled, 'bi-slash-circle', 'secondary'),
    statCard('Check-in Valid', d.valid_checkin, 'bi-geo-alt', 'info'),
    statCard('Check-in Gagal', d.failed_checkin, 'bi-exclamation-triangle', 'warning'),
  ]
})

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="sp-page-title mb-0"><i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>Laporan</h5>
      <button v-if="data && tab !== 'monthly'" class="btn btn-sm btn-outline-success" @click="exportCsv">
        <i class="bi bi-filetype-csv me-1"></i>Export CSV
      </button>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-pills mb-3">
      <li class="nav-item"><a class="nav-link" :class="{ active: tab === 'daily' }" href="#" @click.prevent="tab = 'daily'; load()"><i class="bi bi-sun me-1"></i>Harian</a></li>
      <li class="nav-item"><a class="nav-link" :class="{ active: tab === 'monthly' }" href="#" @click.prevent="tab = 'monthly'; load()"><i class="bi bi-calendar-month me-1"></i>Bulanan</a></li>
      <li class="nav-item"><a class="nav-link" :class="{ active: tab === 'attendance' }" href="#" @click.prevent="tab = 'attendance'; load()"><i class="bi bi-person-check me-1"></i>Attendance</a></li>
    </ul>

    <!-- Filter bar -->
    <div class="card sp-card mb-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-end">
          <template v-if="tab === 'daily'">
            <div class="col-auto"><label class="form-label small mb-1">Tanggal</label><input v-model="daily.date" type="date" class="form-control form-control-sm" /></div>
            <div class="col-auto"><button class="btn btn-sm btn-primary" @click="load"><i class="bi bi-search"></i></button></div>
          </template>
          <template v-else-if="tab === 'monthly'">
            <div class="col-auto"><label class="form-label small mb-1">Bulan</label>
              <select v-model.number="monthly.month" class="form-select form-select-sm">
                <option v-for="m in monthOptions" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>
            <div class="col-auto"><label class="form-label small mb-1">Tahun</label>
              <select v-model.number="monthly.year" class="form-select form-select-sm">
                <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
              </select>
            </div>
            <div class="col-auto"><button class="btn btn-sm btn-primary" @click="load"><i class="bi bi-search"></i></button></div>
          </template>
          <template v-else>
            <div class="col-auto"><label class="form-label small mb-1">Dari</label><input v-model="attendance.from" type="date" class="form-control form-control-sm" /></div>
            <div class="col-auto"><label class="form-label small mb-1">Sampai</label><input v-model="attendance.to" type="date" class="form-control form-control-sm" /></div>
            <div class="col-auto"><button class="btn btn-sm btn-primary" @click="load"><i class="bi bi-search"></i></button></div>
            <div class="col-auto"><button class="btn btn-sm btn-outline-success" @click="exportCsv"><i class="bi bi-filetype-csv me-1"></i>Export</button></div>
          </template>
        </div>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
    <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>

    <template v-if="!loading && data">
      <!-- DAILY -->
      <template v-if="tab === 'daily'">
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-4 col-xl-2" v-for="c in dailyCards" :key="c.label">
            <div class="card sp-card h-100"><div class="card-body d-flex align-items-center gap-3">
              <div class="stat-icon" :class="`bg-${c.color} bg-opacity-10 text-${c.color}`"><i class="bi" :class="c.icon"></i></div>
              <div><div class="fs-4 fw-bold">{{ c.value }}</div><div class="small text-muted">{{ c.label }}</div></div>
            </div></div>
          </div>
        </div>
      </template>

      <!-- MONTHLY -->
      <template v-else-if="tab === 'monthly'">
        <div class="card sp-card mb-3">
          <div class="card-body">
            <h6 class="fw-bold">{{ monthName }} {{ monthly.year }}</h6>
            <div class="row text-center mt-3">
              <div class="col-3"><div class="fs-4 fw-bold text-primary">{{ data.total_patrol }}</div><div class="small text-muted">Total</div></div>
              <div class="col-3"><div class="fs-4 fw-bold text-success">{{ data.completed }}</div><div class="small text-muted">Completed</div></div>
              <div class="col-3"><div class="fs-4 fw-bold text-warning">{{ data.incomplete }}</div><div class="small text-muted">Incomplete</div></div>
              <div class="col-3"><div class="fs-4 fw-bold text-secondary">{{ data.cancelled }}</div><div class="small text-muted">Cancelled</div></div>
            </div>
          </div>
        </div>
        <div class="card sp-card">
          <div class="card-header">Per Petugas</div>
          <div class="card-body p-0">
            <div v-if="data.per_officer.length === 0" class="text-center text-muted py-3">Tidak ada data</div>
            <div v-else class="table-responsive">
              <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>Petugas</th><th>Total</th><th>Completed</th><th>Incomplete</th><th>Kepatuhan</th><th>Rata-rata Durasi</th></tr></thead>
                <tbody>
                  <tr v-for="o in data.per_officer" :key="o.officer_id">
                    <td><div class="fw-semibold">{{ o.officer_name }}</div><div class="small text-muted">{{ o.employee_code }}</div></td>
                    <td>{{ o.total_patrol }}</td>
                    <td class="text-success">{{ o.completed }}</td>
                    <td class="text-warning">{{ o.incomplete }}</td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar bg-success" :style="{ width: o.compliance_percentage + '%' }"></div></div>
                        <span class="small fw-semibold">{{ o.compliance_percentage }}%</span>
                      </div>
                    </td>
                    <td class="small">{{ durLabel(o.avg_duration_seconds) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </template>

      <!-- ATTENDANCE -->
      <template v-else>
        <div class="card sp-card">
          <div class="card-header">Kepatuhan Patroli Petugas</div>
          <div class="card-body p-0">
            <div v-if="data.officers.length === 0" class="text-center text-muted py-3">Tidak ada petugas security</div>
            <div v-else class="table-responsive">
              <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>Petugas</th><th>Total Jadwal</th><th>Patroli</th><th>Completed</th><th>Incomplete</th><th>Missed</th><th>Compliance</th></tr></thead>
                <tbody>
                  <tr v-for="o in data.officers" :key="o.officer_id">
                    <td><div class="fw-semibold">{{ o.officer_name }}</div><div class="small text-muted">{{ o.employee_code }}</div></td>
                    <td>{{ o.total_scheduled }}</td>
                    <td>{{ o.total_patrol }}</td>
                    <td class="text-success">{{ o.completed }}</td>
                    <td class="text-warning">{{ o.incomplete }}</td>
                    <td class="text-danger">{{ o.missed }}</td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar" :class="o.compliance_percentage >= 80 ? 'bg-success' : o.compliance_percentage >= 50 ? 'bg-warning' : 'bg-danger'" :style="{ width: o.compliance_percentage + '%' }"></div></div>
                        <span class="small fw-semibold">{{ o.compliance_percentage }}%</span>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
