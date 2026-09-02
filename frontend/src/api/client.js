import axios from 'axios'

const client = axios.create({
  baseURL: '/api/v1',
  timeout: 30000,
})

// Attach bearer token on every request
client.interceptors.request.use((config) => {
  const token = localStorage.getItem('sp_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Handle 401 (token expired / invalid)
client.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem('sp_token')
      localStorage.removeItem('sp_user')
      if (window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }
    return Promise.reject(err)
  },
)

export default client

export function apiError(err) {
  const data = err.response?.data
  if (data?.message) return data.message
  if (err.code === 'ERR_NETWORK') return 'Tidak dapat terhubung ke server'
  return err.message || 'Terjadi kesalahan'
}

export function apiErrorCode(err) {
  return err.response?.data?.error_code || null
}
