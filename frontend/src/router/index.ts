import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/authStore'

const Dashboard = () => import('@/pages/Dashboard.vue')
const Trades = () => import('@/pages/Trades.vue')
const TradeFormPage = () => import('@/pages/TradeFormPage.vue')
const MissedTrades = () => import('@/pages/MissedTrades.vue')
const MissedTradeFormPage = () => import('@/pages/MissedTradeFormPage.vue')
const Milestones = () => import('@/pages/Milestones.vue')
const Accounts = () => import('@/pages/Accounts.vue')
const LotsCalculatorPage = () => import('@/pages/LotsCalculatorPage.vue')
const ChecklistBuilderPage = () => import('@/pages/ChecklistBuilderPage.vue')
const SettingsPage = () => import('@/pages/SettingsPage.vue')
const LoginPage = () => import('@/pages/LoginPage.vue')
const UiRegressionPage = () => import('@/pages/UiRegressionPage.vue')

const includeVisualRoutes = import.meta.env.DEV || import.meta.env.VITE_ENABLE_VISUAL_ROUTES === '1'
const visualRoutes: RouteRecordRaw[] = includeVisualRoutes
  ? [
      {
        path: '/__visual-regression',
        name: 'visual-regression',
        component: UiRegressionPage,
        meta: {
          public: true,
          layout: 'auth',
        },
      },
    ]
  : []

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: '/overview',
    },
    {
      path: '/login',
      name: 'login',
      component: LoginPage,
      meta: {
        layout: 'auth',
        guestOnly: true,
      },
    },
    {
      path: '/overview',
      redirect: '/dashboard',
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: Dashboard,
    },
    {
      path: '/trades',
      name: 'trades',
      component: Trades,
    },
    {
      path: '/trades/new',
      name: 'trades-new',
      component: TradeFormPage,
    },
    {
      path: '/tools/lots-calculate',
      name: 'tools-lots-calculate',
      component: LotsCalculatorPage,
    },
    {
      path: '/settings/hub',
      name: 'settings',
      alias: ['/settings'],
      component: SettingsPage,
    },
    {
      path: '/settings/checklists',
      name: 'settings-checklists',
      component: ChecklistBuilderPage,
    },
    {
      path: '/trades/:id/edit',
      name: 'trades-edit',
      component: TradeFormPage,
    },
    {
      path: '/missed-trades',
      name: 'missed-trades',
      component: MissedTrades,
    },
    {
      path: '/missed-trades/new',
      name: 'missed-trades-new',
      component: MissedTradeFormPage,
    },
    {
      path: '/missed-trades/:id/edit',
      name: 'missed-trades-edit',
      component: MissedTradeFormPage,
    },
    {
      path: '/accounts',
      name: 'accounts',
      component: Accounts,
    },
    {
      path: '/milestones',
      redirect: '/progress',
    },
    {
      path: '/progress',
      name: 'progress',
      component: Milestones,
    },
    ...visualRoutes,
  ],
})

router.beforeEach(async (to) => {
  const isLocalVisualHarness = typeof window !== 'undefined'
    && ['localhost', '127.0.0.1'].includes(window.location.hostname)
  const bypassAuthForVisualTests = isLocalVisualHarness && to.query.visual === '1'
  if (bypassAuthForVisualTests) {
    return true
  }

  const authStore = useAuthStore()
  if (!authStore.initialized) {
    await authStore.initialize()
  }

  const isGuestOnly = to.matched.some((record) => record.meta.guestOnly)
  if (isGuestOnly && authStore.isAuthenticated) {
    const redirectTarget = typeof to.query.redirect === 'string' && to.query.redirect !== ''
      ? to.query.redirect
      : '/dashboard'
    return redirectTarget
  }

  const isPublic = to.matched.some((record) => record.meta.public)
  if (!isPublic && !isGuestOnly && !authStore.isAuthenticated) {
    return {
      path: '/login',
      query: { redirect: to.fullPath },
    }
  }

  return true
})

export default router
