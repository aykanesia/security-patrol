import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/',
    component: () => import('../layouts/MainLayout.vue'),
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('../views/DashboardView.vue'),
        meta: { title: 'Dashboard', roles: ['super_admin', 'supervisor'] },
      },
      {
        path: 'monitoring',
        name: 'monitoring',
        component: () => import('../views/MonitoringView.vue'),
        meta: { title: 'Monitoring Live', roles: ['super_admin', 'supervisor'] },
      },
      {
        path: 'sessions',
        name: 'sessions',
        component: () => import('../views/SessionHistoryView.vue'),
        meta: { title: 'Histori Patroli', roles: ['super_admin', 'supervisor'] },
      },
      {
        path: 'reports',
        name: 'reports',
        component: () => import('../views/ReportsView.vue'),
        meta: { title: 'Laporan', roles: ['super_admin', 'supervisor'] },
      },
      {
        path: 'users',
        name: 'users',
        component: () => import('../views/admin/UsersView.vue'),
        meta: { title: 'Manajemen Petugas', roles: ['super_admin'] },
      },
      {
        path: 'areas',
        name: 'areas',
        component: () => import('../views/admin/AreasView.vue'),
        meta: { title: 'Area', roles: ['super_admin'] },
      },
      {
        path: 'checkpoints',
        name: 'checkpoints',
        component: () => import('../views/admin/CheckpointsView.vue'),
        meta: { title: 'Checkpoint & QR', roles: ['super_admin'] },
      },
      {
        path: 'routes',
        name: 'routes',
        component: () => import('../views/admin/RoutesView.vue'),
        meta: { title: 'Rute Patroli', roles: ['super_admin'] },
      },
      {
        path: 'schedules',
        name: 'schedules',
        component: () => import('../views/admin/SchedulesView.vue'),
        meta: { title: 'Jadwal Patroli', roles: ['super_admin', 'supervisor'] },
      },
      {
        path: 'devices',
        name: 'devices',
        component: () => import('../views/admin/DevicesView.vue'),
        meta: { title: 'Perangkat', roles: ['super_admin'] },
      },
      {
        path: 'audit',
        name: 'audit',
        component: () => import('../views/admin/AuditLogsView.vue'),
        meta: { title: 'Audit Log', roles: ['super_admin'] },
      },
      {
        path: 'notifications',
        name: 'notifications',
        component: () => import('../views/NotificationsView.vue'),
        meta: { title: 'Notifikasi', roles: ['super_admin', 'supervisor'] },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.meta.public) {
    if (auth.isAuthenticated && to.name === 'login') return { name: 'dashboard' }
    return true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  const roles = to.meta.roles
  if (roles && !roles.includes(auth.user?.role)) {
    return { name: 'dashboard' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — Security Patrol` : 'Security Patrol'
})

export default router
