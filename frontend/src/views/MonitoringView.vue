<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
import L from 'leaflet'
import client from '../api/client'

const mapEl = ref(null)
const loading = ref(true)
const patrols = ref([])
const lastUpdate = ref(null)
let map = null
let markers = []
let timer = null

// Fix default marker icons when bundled by Vite
delete L.Icon.Default.prototype._getIconUrl
L.Icon.Default.mergeOptions({
  iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
  iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
  shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
})

function officerIcon() {
  return L.divIcon({
    className: '',
    html: `<div style="width:26px;height:26px;background:#dc3545;border:2px solid #fff;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px"><i class="bi bi-person"></i></div>`,
    iconSize: [26, 26],
    iconAnchor: [13, 13],
  })
}

function checkpointIcon() {
  return L.divIcon({
    className: '',
    html: `<div style="width:14px;height:14px;background:#0d6efd;border:2px solid #fff;border-radius:50%;box-shadow:0 0 4px rgba(0,0,0,.4)"></div>`,
    iconSize: [14, 14],
    iconAnchor: [7, 7],
  })
}

async function load() {
  try {
    const { data } = await client.get('/dashboard/active-patrols')
    patrols.value = data.data
    lastUpdate.value = new Date()
    render()
  } catch (e) {
    /* interceptor handles auth */
  } finally {
    loading.value = false
  }
}

function render() {
  if (!map) return

  markers.forEach((m) => map.removeLayer(m))
  markers = []

  const bounds = []

  patrols.value.forEach((p) => {
    // route checkpoints
    const cpLatLngs = []
    p.checkpoints.forEach((cp) => {
      const m = L.marker([cp.latitude, cp.longitude], { icon: checkpointIcon() }).addTo(map)
      m.bindPopup(`<strong>${cp.code}</strong> — ${cp.name}<br/>Urutan ${cp.sequence}`)
      markers.push(m)
      cpLatLngs.push([cp.latitude, cp.longitude])
      bounds.push([cp.latitude, cp.longitude])
    })

    // route line
    if (cpLatLngs.length > 1) {
      const line = L.polyline(cpLatLngs, { color: '#6c757d', weight: 2, dashArray: '4 6' }).addTo(map)
      markers.push(line)
    }

    // officer position
    const pos = p.position
    if (pos.latitude && pos.longitude) {
      const m = L.marker([pos.latitude, pos.longitude], { icon: officerIcon() }).addTo(map)
      m.bindPopup(`
        <strong>${p.officer.name}</strong> (${p.officer.employee_code})<br/>
        Rute: ${p.route.name} (${p.route.area})<br/>
        Progress: ${p.progress.completed}/${p.progress.total} (${p.progress.percentage}%)<br/>
        Mulai: ${new Date(p.started_at).toLocaleTimeString('id-ID')}
      `)
      markers.push(m)
      bounds.push([pos.latitude, pos.longitude])
    }
  })

  if (bounds.length > 0) {
    map.fitBounds(bounds, { padding: [40, 40] })
  }
}

onMounted(async () => {
  await nextTick()
  // Inisialisasi map hanya setelah data dimuat & container terlihat.
  // Sebelumnya map dibuat saat `loading=true` (elemen masih display:none)
  // → Leaflet mengukur ukuran 0 → peta terpotong/tidak sempurna.
  await load()
  await nextTick()
  map = L.map(mapEl.value).setView([-6.26, 106.79], 14)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map)

  render()
  // Pastikan ukuran peta benar setelah container tampil (mis. saat tab pertama dibuka)
  setTimeout(() => map?.invalidateSize(), 100)
  timer = setInterval(load, 20000)
})
onUnmounted(() => {
  clearInterval(timer)
  map?.remove()
})
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h5 class="sp-page-title mb-0"><i class="bi bi-map me-2 text-primary"></i>Monitoring Patroli Live</h5>
        <div class="small text-muted">
          Posisi diperbarui tiap scan / mulai / selesai patroli
          <span v-if="lastUpdate" class="ms-2"><i class="bi bi-arrow-clockwise"></i> {{ lastUpdate.toLocaleTimeString('id-ID') }}</span>
        </div>
      </div>
      <button class="btn btn-sm btn-outline-primary" @click="load"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
    </div>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card sp-card">
          <div class="card-body p-2">
            <div v-if="loading" class="text-center py-5">
              <div class="spinner-border text-primary"></div>
            </div>
            <div ref="mapEl" v-show="!loading" style="height: 520px"></div>
          </div>
        </div>
        <div class="small text-muted mt-2">
          <span class="me-3"><span style="display:inline-block;width:10px;height:10px;background:#dc3545;border-radius:50%;margin-right:4px"></span>Petugas</span>
          <span><span style="display:inline-block;width:10px;height:10px;background:#0d6efd;border-radius:50%;margin-right:4px"></span>Checkpoint</span>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card sp-card">
          <div class="card-header">Daftar Patroli Aktif ({{ patrols.length }})</div>
          <div class="card-body p-0" style="max-height: 520px; overflow-y: auto">
            <div v-if="patrols.length === 0" class="text-center text-muted py-4">Tidak ada patroli aktif</div>
            <div v-for="p in patrols" :key="p.session_code" class="border-bottom p-3">
              <div class="d-flex justify-content-between">
                <strong><i class="bi bi-person-circle text-danger me-1"></i>{{ p.officer.name }}</strong>
                <span class="badge bg-success">RUNNING</span>
              </div>
              <div class="small text-muted mb-2">
                {{ p.route.name }} · {{ p.route.area }}<br />
                <span class="text-truncate d-inline-block" style="max-width:180px">{{ p.session_code }}</span>
              </div>
              <div class="d-flex justify-content-between small mb-1">
                <span>{{ p.progress.completed }}/{{ p.progress.total }} checkpoint</span>
                <span class="fw-semibold">{{ p.progress.percentage }}%</span>
              </div>
              <div class="progress" style="height:6px">
                <div class="progress-bar bg-success" :style="{ width: p.progress.percentage + '%' }"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
