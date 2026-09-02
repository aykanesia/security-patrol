<script setup>
import { onMounted, reactive, ref } from 'vue'
import client, { apiError } from '../../api/client'

const areas = ref([])
const loading = ref(false)
const error = ref('')
const modal = reactive({ show: false, mode: 'create', id: null })
const form = reactive({ name: '', description: '', status: 'ACTIVE' })
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await client.get('/admin/areas', { params: { all: true } })
    areas.value = data.data
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(modal, { show: true, mode: 'create', id: null })
  Object.assign(form, { name: '', description: '', status: 'ACTIVE' })
}

function openEdit(a) {
  Object.assign(modal, { show: true, mode: 'edit', id: a.id })
  Object.assign(form, { name: a.name, description: a.description || '', status: a.status })
}

async function save() {
  if (!form.name) return
  saving.value = true
  try {
    if (modal.mode === 'create') await client.post('/admin/areas', form)
    else await client.put(`/admin/areas/${modal.id}`, form)
    modal.show = false
    load()
  } catch (e) {
    alert(apiError(e))
  } finally {
    saving.value = false
  }
}

async function removeArea(a) {
  if (!confirm(`Hapus area ${a.name}?`)) return
  try {
    await client.delete(`/admin/areas/${a.id}`)
    load()
  } catch (e) {
    alert(apiError(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="sp-page-title mb-0"><i class="bi bi-house-gear me-2 text-primary"></i>Area / Cluster</h5>
      <button class="btn btn-sm btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Tambah Area</button>
    </div>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="row g-3">
      <div v-if="loading" class="text-center py-5 col-12"><div class="spinner-border text-primary"></div></div>
      <template v-else>
        <div v-for="a in areas" :key="a.id" class="col-md-6 col-xl-4">
          <div class="card sp-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                  <h6 class="fw-bold mb-0">{{ a.name }}</h6>
                  <span class="badge" :class="a.status === 'ACTIVE' ? 'bg-success' : 'bg-secondary'">{{ a.status }}</span>
                </div>
                <div class="btn-group">
                  <button class="btn btn-sm btn-outline-primary" @click="openEdit(a)"><i class="bi bi-pencil"></i></button>
                  <button class="btn btn-sm btn-outline-danger" @click="removeArea(a)"><i class="bi bi-trash"></i></button>
                </div>
              </div>
              <p class="small text-muted mb-2">{{ a.description || 'Tanpa deskripsi' }}</p>
              <div class="d-flex gap-3 small">
                <span class="badge bg-light text-dark border"><i class="bi bi-qr-code me-1"></i>{{ a.checkpoints_count }} checkpoint</span>
                <span class="badge bg-light text-dark border"><i class="bi bi-signpost-2 me-1"></i>{{ a.routes_count }} rute</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <div v-if="modal.show" class="modal fade show d-block" tabindex="-1" @click.self="modal.show = false">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h6 class="modal-title">{{ modal.mode === 'create' ? 'Tambah Area' : 'Edit Area' }}</h6>
            <button class="btn-close" @click="modal.show = false"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small">Nama Area</label>
              <input v-model="form.name" class="form-control" placeholder="Cluster Mawar" required />
            </div>
            <div class="mb-3">
              <label class="form-label small">Deskripsi</label>
              <textarea v-model="form.description" class="form-control" rows="2"></textarea>
            </div>
            <div class="mb-2">
              <label class="form-label small">Status</label>
              <select v-model="form.status" class="form-select">
                <option value="ACTIVE">ACTIVE</option>
                <option value="INACTIVE">INACTIVE</option>
              </select>
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
