<script setup>
import { onMounted, reactive, ref } from 'vue'
import client, { apiError } from '../../api/client'

const users = ref([])
const roles = ref([])
const meta = ref({})
const loading = ref(false)
const error = ref('')

const filters = reactive({ role: '', status: '', search: '', page: 1 })
const modal = reactive({ show: false, mode: 'create', id: null })
const form = reactive({
  role_id: '', employee_code: '', name: '', username: '', password: '',
  phone: '', status: 'ACTIVE',
})
const saving = ref(false)
const formError = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = { page: filters.page, per_page: 15 }
    if (filters.role) params.role = filters.role
    if (filters.status) params.status = filters.status
    if (filters.search) params.search = filters.search
    const { data } = await client.get('/admin/users', { params })
    users.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}

async function loadRoles() {
  const { data } = await client.get('/admin/roles')
  roles.value = data.data
}

function openCreate() {
  Object.assign(modal, { show: true, mode: 'create', id: null })
  Object.assign(form, { role_id: '', employee_code: '', name: '', username: '', password: '', phone: '', status: 'ACTIVE' })
  formError.value = ''
}

function openEdit(u) {
  Object.assign(modal, { show: true, mode: 'edit', id: u.id })
  Object.assign(form, {
    role_id: u.role_id, employee_code: u.employee_code || '', name: u.name,
    username: u.username, password: '', phone: u.phone || '', status: u.status,
  })
  formError.value = ''
}

async function save() {
  saving.value = true
  formError.value = ''
  try {
    const payload = { ...form }
    if (!payload.password && modal.mode === 'edit') delete payload.password
    if (modal.mode === 'create') {
      await client.post('/admin/users', payload)
    } else {
      await client.put(`/admin/users/${modal.id}`, payload)
    }
    modal.show = false
    load()
  } catch (e) {
    formError.value = apiError(e)
  } finally {
    saving.value = false
  }
}

async function removeUser(u) {
  if (!confirm(`Hapus pengguna ${u.name}?`)) return
  try {
    await client.delete(`/admin/users/${u.id}`)
    load()
  } catch (e) {
    alert(apiError(e))
  }
}

const roleBadge = (r) => ({
  super_admin: 'bg-danger',
  supervisor: 'bg-warning text-dark',
  security: 'bg-info text-dark',
}[r] || 'bg-secondary')

const statusBadge = (s) => ({
  ACTIVE: 'bg-success',
  INACTIVE: 'bg-secondary',
  SUSPENDED: 'bg-danger',
}[s] || 'bg-secondary')

function applyFilters() {
  filters.page = 1
  load()
}

onMounted(() => {
  loadRoles()
  load()
})
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="sp-page-title mb-0"><i class="bi bi-people me-2 text-primary"></i>Manajemen Petugas</h5>
      <button class="btn btn-sm btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Tambah Petugas</button>
    </div>

    <div class="card sp-card mb-3">
      <div class="card-body py-2">
        <div class="row g-2">
          <div class="col-md-4"><input v-model.trim="filters.search" class="form-control form-control-sm" placeholder="Cari nama / username / NIK" @keyup.enter="applyFilters" /></div>
          <div class="col-md-2">
            <select v-model="filters.role" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua Role</option>
              <option v-for="r in roles" :key="r.name" :value="r.name">{{ r.name }}</option>
            </select>
          </div>
          <div class="col-md-2">
            <select v-model="filters.status" class="form-select form-select-sm" @change="applyFilters">
              <option value="">Semua Status</option>
              <option value="ACTIVE">ACTIVE</option>
              <option value="INACTIVE">INACTIVE</option>
              <option value="SUSPENDED">SUSPENDED</option>
            </select>
          </div>
          <div class="col-md-2"><button class="btn btn-sm btn-primary" @click="applyFilters"><i class="bi bi-search"></i></button></div>
        </div>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="card sp-card">
      <div class="card-body p-0">
        <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        <div v-else-if="users.length === 0" class="text-center text-muted py-4">Tidak ada data pengguna</div>
        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>NIK</th><th>Nama</th><th>Username</th><th>Role</th><th>Telepon</th><th>Status</th><th>Terakhir Login</th><th class="text-end">Aksi</th></tr>
            </thead>
            <tbody>
              <tr v-for="u in users" :key="u.id">
                <td class="small text-muted">{{ u.employee_code || '-' }}</td>
                <td class="fw-semibold">{{ u.name }}</td>
                <td class="small">{{ u.username }}</td>
                <td><span class="badge" :class="roleBadge(u.role)">{{ u.role }}</span></td>
                <td class="small">{{ u.phone || '-' }}</td>
                <td><span class="badge" :class="statusBadge(u.status)">{{ u.status }}</span></td>
                <td class="small text-muted">{{ u.last_login_at || '-' }}</td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary me-1" @click="openEdit(u)"><i class="bi bi-pencil"></i></button>
                  <button class="btn btn-sm btn-outline-danger" @click="removeUser(u)"><i class="bi bi-trash"></i></button>
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
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h6 class="modal-title">{{ modal.mode === 'create' ? 'Tambah Petugas' : 'Edit Petugas' }}</h6>
            <button class="btn-close" @click="modal.show = false"></button>
          </div>
          <div class="modal-body">
            <div v-if="formError" class="alert alert-danger py-2 small">{{ formError }}</div>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label small">Role</label>
                <select v-model="form.role_id" class="form-select form-select-sm" required>
                  <option value="" disabled>Pilih role</option>
                  <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small">Kode Karyawan</label>
                <input v-model="form.employee_code" class="form-control form-control-sm" placeholder="SEC001" />
              </div>
              <div class="col-md-6">
                <label class="form-label small">Nama Lengkap</label>
                <input v-model="form.name" class="form-control form-control-sm" required />
              </div>
              <div class="col-md-6">
                <label class="form-label small">Username</label>
                <input v-model="form.username" class="form-control form-control-sm" required />
              </div>
              <div class="col-md-6">
                <label class="form-label small">{{ modal.mode === 'create' ? 'Password' : 'Password (kosongkan jika tetap)' }}</label>
                <input v-model="form.password" type="password" class="form-control form-control-sm" :required="modal.mode === 'create'" minlength="8" />
              </div>
              <div class="col-md-6">
                <label class="form-label small">Telepon</label>
                <input v-model="form.phone" class="form-control form-control-sm" />
              </div>
              <div class="col-md-6">
                <label class="form-label small">Status</label>
                <select v-model="form.status" class="form-select form-select-sm">
                  <option value="ACTIVE">ACTIVE</option>
                  <option value="INACTIVE">INACTIVE</option>
                  <option value="SUSPENDED">SUSPENDED</option>
                </select>
              </div>
            </div>
          </div>
          <div class="modal-footer py-2">
            <button class="btn btn-sm btn-secondary" @click="modal.show = false">Batal</button>
            <button class="btn btn-sm btn-primary" :disabled="saving" @click="save">
              <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>Simpan
            </button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="modal.show" class="modal-backdrop fade show"></div>
  </div>
</template>
