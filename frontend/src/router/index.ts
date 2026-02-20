import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '@/pages/Dashboard.vue'
import Trades from '@/pages/Trades.vue'
import MissedTrades from '@/pages/MissedTrades.vue'
import Milestones from '@/pages/Milestones.vue'

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
      path: '/missed-trades',
      name: 'missed-trades',
      component: MissedTrades,
    },
    {
      path: '/milestones',
      name: 'milestones',
      component: Milestones,
    },
  ],
})

export default router
