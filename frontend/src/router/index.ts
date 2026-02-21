import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '@/pages/Dashboard.vue'
import Trades from '@/pages/Trades.vue'
import TradeFormPage from '@/pages/TradeFormPage.vue'
import MissedTrades from '@/pages/MissedTrades.vue'
import MissedTradeFormPage from '@/pages/MissedTradeFormPage.vue'
import Milestones from '@/pages/Milestones.vue'
import Accounts from '@/pages/Accounts.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
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
      name: 'milestones',
      component: Milestones,
    },
  ],
})

export default router
