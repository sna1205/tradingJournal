import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '@/pages/Dashboard.vue'
import Trades from '@/pages/Trades.vue'
import TradeFormPage from '@/pages/TradeFormPage.vue'
import MissedTrades from '@/pages/MissedTrades.vue'
import MissedTradeFormPage from '@/pages/MissedTradeFormPage.vue'
import Milestones from '@/pages/Milestones.vue'
import Accounts from '@/pages/Accounts.vue'
import PreTradeCheckPage from '@/pages/PreTradeCheckPage.vue'
import LotsCalculatorPage from '@/pages/LotsCalculatorPage.vue'
import UiRegressionPage from '@/pages/UiRegressionPage.vue'
import ChecklistBuilderPage from '@/pages/ChecklistBuilderPage.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: '/overview',
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
      path: '/tools/pre-trade-check',
      name: 'tools-pre-trade-check',
      component: PreTradeCheckPage,
    },
    {
      path: '/tools/lots-calculate',
      name: 'tools-lots-calculate',
      component: LotsCalculatorPage,
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
    {
      path: '/__visual-regression',
      name: 'visual-regression',
      component: UiRegressionPage,
    },
  ],
})

export default router
