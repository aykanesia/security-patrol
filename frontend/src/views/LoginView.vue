<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { apiError } from '../api/client'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const username = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')

async function submit() {
  if (!username.value || !password.value) {
    error.value = 'Username dan password wajib diisi'
    return
  }
  loading.value = true
  error.value = ''
  try {
    await auth.login(username.value, password.value)
    router.push(route.query.redirect || '/')
  } catch (e) {
    error.value = apiError(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-vh-100 d-flex align-items-center justify-content-center login-bg">
    <div class="card sp-card login-card">
      <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
          <div class="stat-icon bg-primary text-white mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:56px;height:56px">
            <i class="bi bi-shield-lock"></i>
          </div>
          <h4 class="fw-bold mb-1">Security Patrol</h4>
          <p class="text-muted small mb-0">Sistem Monitoring Patroli Perumahan</p>
        </div>

        <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

        <form @submit.prevent="submit">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input v-model.trim="username" type="text" class="form-control" autocomplete="username" placeholder="Masukkan username" />
          </div>
          <div class="mb-4">
            <label class="form-label">Password</label>
            <input v-model="password" type="password" class="form-control" autocomplete="current-password" placeholder="Masukkan password" />
          </div>
          <button class="btn btn-primary w-100 py-2" :disabled="loading">
            <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
            Masuk
          </button>
        </form>

        <div class="text-center mt-4 small text-muted">
          Demo: <code>admin</code> / <code>password</code>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.login-bg {
  background: linear-gradient(135deg, #0f2440 0%, #1a3a6b 100%);
}
.login-card {
  width: 100%;
  max-width: 420px;
}
</style>
