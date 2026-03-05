import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/authStore'

const AnalyticsPage = () => import('@/pages/Dashboard.vue')
const Trades = () => import('@/pages/Trades.vue')
const TradeFormPage = () => import('@/pages/TradeFormPage.vue')
const MissedTrades = () => import('@/pages/MissedTrades.vue')
const MissedTradeFormPage = () => import('@/pages/MissedTradeFormPage.vue')
const Milestones = () => import('@/pages/Milestones.vue')
const Accounts = () => import('@/pages/Accounts.vue')
const LotsCalculatorPage = () => import('@/pages/LotsCalculatorPage.vue')
const TradingRulesPage = () => import('@/pages/TradingRulesPage.vue')
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
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }

    if (to.hash) {
      return {
        el: to.hash,
        top: 96,
        behavior: 'smooth',
      }
    }

    if (to.path !== from.path) {
      return { top: 0 }
    }

    return {}
  },
  routes: [
    {
      path: '/',
      redirect: '/overview',
    },
    {
      path: '/auth/login',
      alias: ['/login'],
      name: 'auth-login',
      component: LoginPage,
      meta: {
        public: true,
        layout: 'auth',
        guestOnly: true,
      },
    },
    {
      path: '/auth/register',
      alias: ['/register'],
      name: 'auth-register',
      component: () => import('@/pages/RegisterPage.vue'),
      meta: {
        public: true,
        layout: 'auth',
        guestOnly: true,
        requiresSelfRegister: true,
      },
    },
    {
      path: '/overview',
      redirect: '/dashboard',
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: AnalyticsPage,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/trades',
      name: 'trades',
      component: Trades,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/trades/new',
      name: 'trades-new',
      component: TradeFormPage,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/tools/lots-calculate',
      name: 'tools-lots-calculate',
      component: LotsCalculatorPage,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/settings/hub',
      name: 'settings',
      alias: ['/settings'],
      component: SettingsPage,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/settings/rules',
      name: 'settings-rules',
      component: TradingRulesPage,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/trades/:id/edit',
      name: 'trades-edit',
      component: TradeFormPage,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/missed-trades',
      name: 'missed-trades',
      component: MissedTrades,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/missed-trades/new',
      name: 'missed-trades-new',
      component: MissedTradeFormPage,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/missed-trades/:id/edit',
      name: 'missed-trades-edit',
      component: MissedTradeFormPage,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/accounts',
      name: 'accounts',
      component: Accounts,
      meta: {
        requiresAuth: true,
      },
    },
    {
      path: '/milestones',
      redirect: '/progress',
    },
    {
      path: '/progress',
      name: 'progress',
      component: Milestones,
      meta: {
        requiresAuth: true,
      },
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
  const requiresSelfRegister = to.matched.some((record) => record.meta.requiresSelfRegister)
  if (requiresSelfRegister && !authStore.allowSelfRegister) {
    return { name: 'auth-login' }
  }

  if (isGuestOnly && authStore.isAuthenticated) {
    const redirectTarget = typeof to.query.redirect === 'string' && to.query.redirect !== ''
      ? to.query.redirect
      : '/dashboard'
    return redirectTarget
  }

  const requiresAuth = to.matched.some((record) => record.meta.requiresAuth)
  if (requiresAuth && !authStore.isAuthenticated) {
    return {
      path: '/auth/login',
      query: { redirect: to.fullPath },
    }
  }

  return true
})

export default router
