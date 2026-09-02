<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import client, { apiError } from '../api/client'

const router = useRouter()
const auth = useAuthStore()
const unreadCount = ref(0)
const collapsed = ref(false)

const navItems = computed(() => {
  const canMonitor = auth.canMonitor
  const canAdmin = auth.canAdmin
  const items = []

  if (canMonitor) {
    items.push({ to: '/', label: 'Dashboard', icon: 'bi-speedometer2', exact: true })
    items.push({ to: '/monitoring', label: 'Monitoring Live', icon: 'bi-map' })
    items.push({ to: '/sessions', label: 'Histori Patroli', icon: 'bi-clock-history' })
    items.push({ to: '/reports', label: 'Laporan', icon: 'bi-file-earmark-bar-graph' })
  }
  if (canAdmin) {
    items.push({ type: 'divider' })
    items.push({ to: '/users', label: 'Petugas', icon: 'bi-people' })
    items.push({ to: '/areas', label: 'Area', icon: 'bi-house-gear' })
    items.push({ to: '/checkpoints', label: 'Checkpoint & QR', icon: 'bi-qr-code' })
    items.push({ to: '/routes', label: 'Rute Patroli', icon: 'bi-signpost-2' })
  }
  if (canMonitor) {
    items.push({ to: '/schedules', label: 'Jadwal Patroli', icon: 'bi-calendar-week' })
  }
  if (canAdmin) {
    items.push({ to: '/devices', label: 'Perangkat', icon: 'bi-phone' })
    items.push({ to: '/audit', label: 'Audit Log', icon: 'bi-journal-check' })
  }
  if (canMonitor) {
    items.push({ type: 'divider' })
    items.push({ to: '/notifications', label: 'Notifikasi', icon: 'bi-bell', badge: unreadCount.value })
  }
  return items
})

const isActive = (to) => {
  if (to === '/') return router.currentRoute.value.path === '/'
  return router.currentRoute.value.path.startsWith(to)
}

async function loadUnread() {
  try {
    const { data } = await client.get('/notifications', { params: { unread_only: true, per_page: 1 } })
    unreadCount.value = data.meta.unread_count || 0
  } catch (e) {
    /* ignore */
  }
}

async function doLogout() {
  await auth.logout()
  router.push('/login')
}

onMounted(loadUnread)
</script>

<template>
  <div class="d-flex sp-shell">
    <!-- Sidebar -->
    <aside class="sp-sidebar bg-dark text-white" :class="{ collapsed }">
      <div class="d-flex align-items-center gap-2 px-3 py-3 border-bottom border-secondary">
        <i class="bi bi-shield-lock fs-4 text-primary"></i>
        <div v-if="!collapsed">
          <div class="fw-bold lh-1">SECURITY</div>
          <div class="small text-secondary lh-1">PATROL MONITOR</div>
        </div>
      </div>

      <nav class="flex-grow-1 py-2 overflow-auto">
        <template v-for="(item, idx) in navItems" :key="idx">
          <hr v-if="item.type === 'divider'" class="my-2 border-secondary mx-3" />
          <router-link
            v-else
            :to="item.to"
            class="sp-nav-link d-flex align-items-center gap-2 px-3 py-2 text-decoration-none"
            :class="{ active: isActive(item.to) }"
            :title="collapsed ? item.label : ''"
          >
            <i class="bi" :class="item.icon"></i>
            <span v-if="!collapsed" class="flex-grow-1">{{ item.label }}</span>
            <span v-if="!collapsed && item.badge" class="badge bg-danger rounded-pill">{{ item.badge }}</span>
          </router-link>
        </template>
      </nav>

      <div class="border-top border-secondary p-2">
        <button class="btn btn-sm btn-outline-secondary w-100 text-start" @click="collapsed = !collapsed">
          <i class="bi" :class="collapsed ? 'bi-chevron-double-right' : 'bi-chevron-double-left'"></i>
          <span v-if="!collapsed" class="ms-2">Sembunyikan</span>
        </button>
      </div>
    </aside>

    <!-- Main -->
    <div class="flex-grow-1 d-flex flex-column min-vw-0">
      <header class="sp-topbar bg-white border-bottom d-flex align-items-center px-4 py-2">
        <h6 class="mb-0 fw-bold text-truncate">{{ $route.meta.title || '' }}</h6>
        <div class="ms-auto d-flex align-items-center gap-3">
          <router-link to="/notifications" class="text-muted position-relative text-decoration-none">
            <i class="bi bi-bell fs-5"></i>
            <span v-if="unreadCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem">{{ unreadCount }}</span>
          </router-link>
          <div class="dropdown">
            <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
              <i class="bi bi-person-circle fs-5"></i>
              <span>{{ auth.user?.name }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><span class="dropdown-item-text small text-muted">{{ auth.user?.role }}</span></li>
              <li><hr class="dropdown-divider" /></li>
              <li><a class="dropdown-item" href="#" @click.prevent="doLogout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
          </div>
        </div>
      </header>

      <main class="flex-grow-1 p-4 overflow-auto">
        <router-view />
      </main>
    </div>
  </div>
</template>

<style scoped>
.sp-shell {
  min-height: 100vh;
}
.sp-sidebar {
  width: var(--sp-sidebar-width);
  transition: width .2s;
  display: flex;
  flex-direction: column;
}
.sp-sidebar.collapsed {
  width: 64px;
}
.sp-sidebar .sp-nav-link {
  color: #b8c4d4;
  border-left: 3px solid transparent;
  white-space: nowrap;
}
.sp-sidebar .sp-nav-link:hover {
  color: #fff;
  background: rgba(255,255,255,.06);
}
.sp-sidebar .sp-nav-link.active {
  color: #fff;
  background: rgba(13,110,253,.25);
  border-left-color: #0d6efd;
}
.sp-topbar {
  min-height: 56px;
}
</style>
