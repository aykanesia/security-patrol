<script setup>
import { onMounted, ref } from 'vue'
import client, { apiError } from '../api/client'

const notifs = ref([])
const meta = ref({})
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await client.get('/notifications', { params: { per_page: 30 } })
    notifs.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

async function markRead(n) {
  if (n.read_at) return
  try {
    await client.post(`/notifications/${n.id}/read`)
    n.read_at = new Date().toISOString()
  } catch (e) { /* ignore */ }
}

async function markAll() {
  try {
    await client.post('/notifications/read-all')
    notifs.value.forEach((n) => (n.read_at = n.read_at || new Date().toISOString()))
  } catch (e) { /* ignore */ }
}

const iconFor = (t) => ({
  patrol_missed: 'bi-exclamation-triangle text-danger',
  patrol_not_started: 'bi-clock text-warning',
  checkpoint_missed: 'bi-geo-alt text-danger',
  system: 'bi-gear text-secondary',
}[t] || 'bi-bell text-primary')

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="sp-page-title mb-0"><i class="bi bi-bell me-2 text-primary"></i>Notifikasi</h5>
      <button class="btn btn-sm btn-outline-secondary" @click="markAll"><i class="bi bi-check2-all me-1"></i>Tandai Semua Dibaca</button>
    </div>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="card sp-card">
      <div class="card-body p-0">
        <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        <div v-else-if="notifs.length === 0" class="text-center text-muted py-4">
          <i class="bi bi-bell-slash fs-3 d-block mb-2"></i>Tidak ada notifikasi
        </div>
        <div v-else>
          <div
            v-for="n in notifs"
            :key="n.id"
            class="d-flex gap-3 p-3 border-bottom align-items-start"
            :class="{ 'bg-light': !n.read_at }"
            @click="markRead(n)"
          >
            <i class="bi fs-4 mt-1" :class="iconFor(n.type)"></i>
            <div class="flex-grow-1">
              <div class="fw-semibold small">
                {{ n.title }}
                <span v-if="!n.read_at" class="badge bg-primary ms-1">baru</span>
              </div>
              <div class="small text-muted">{{ n.message }}</div>
              <div class="small text-muted mt-1" style="font-size:.75rem">{{ n.created_at }}</div>
            </div>
            <i v-if="n.read_at" class="bi bi-check2 text-success"></i>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
