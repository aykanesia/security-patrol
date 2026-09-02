import { defineStore } from 'pinia'
import client from '../api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('sp_token') || '',
    user: JSON.parse(localStorage.getItem('sp_user') || 'null'),
  }),

  getters: {
    isAuthenticated: (s) => !!s.token,
    isSuperAdmin: (s) => s.user?.role === 'super_admin',
    isSupervisor: (s) => s.user?.role === 'supervisor',
    isSecurity: (s) => s.user?.role === 'security',
    canMonitor: (s) => ['super_admin', 'supervisor'].includes(s.user?.role),
    canAdmin: (s) => s.user?.role === 'super_admin',
  },

  actions: {
    bootstrap() {
      // hydrate from localStorage (done in state); optionally refresh /me later
    },

    async login(username, password) {
      const { data } = await client.post('/auth/login', { username, password })
      this.token = data.data.token
      this.user = data.data.user
      localStorage.setItem('sp_token', this.token)
      localStorage.setItem('sp_user', JSON.stringify(this.user))
    },

    async logout() {
      try {
        await client.post('/auth/logout')
      } catch (e) {
        // ignore — token may already be invalid
      }
      this.token = ''
      this.user = null
      localStorage.removeItem('sp_token')
      localStorage.removeItem('sp_user')
    },

    async fetchMe() {
      const { data } = await client.get('/me')
      this.user = data.data
      localStorage.setItem('sp_user', JSON.stringify(data.data))
    },
  },
})
