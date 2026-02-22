<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import {
  LayoutDashboard,
  ClipboardList,
  SearchCheck,
  Goal,
  LineChart,
  Moon,
  Sparkles,
  Sun,
  WalletCards,
  ChevronRight,
  WifiOff,
} from 'lucide-vue-next'
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/uiStore'
import { useSyncStatusStore } from '@/stores/syncStatusStore'

const route = useRoute()
const uiStore = useUiStore()
const syncStatusStore = useSyncStatusStore()
const { theme } = storeToRefs(uiStore)
const { isFallbackMode, lastFallbackContext } = storeToRefs(syncStatusStore)

interface NavItem {
  label: string
  to: string
  icon: unknown
  title: string
  subtitle: string
  navHint: string
}

interface NavSection {
  label: string
  items: NavItem[]
}

const navSections: NavSection[] = [
  {
    label: 'Journal',
    items: [
      {
        label: 'Overview',
        to: '/dashboard',
        icon: LayoutDashboard,
        title: 'Portfolio Overview',
        subtitle: 'Monitor equity, risk, and execution quality across your full book.',
        navHint: 'KPI + charts',
      },
      {
        label: 'Trade Log',
        to: '/trades',
        icon: ClipboardList,
        title: 'Trade Log',
        subtitle: 'Search, review, and maintain your full execution history.',
        navHint: 'Executions',
      },
      {
        label: 'Missed Setups',
        to: '/missed-trades',
        icon: SearchCheck,
        title: 'Missed Setups',
        subtitle: 'Capture skipped opportunities and convert misses into process improvements.',
        navHint: 'Opportunity review',
      },
    ],
  },
  {
    label: 'Management',
    items: [
      {
        label: 'Accounts',
        to: '/accounts',
        icon: WalletCards,
        title: 'Account Center',
        subtitle: 'Manage accounts and track account-level equity, return, and drawdown.',
        navHint: 'Balance control',
      },
      {
        label: 'Progress',
        to: '/progress',
        icon: Goal,
        title: 'Progress & Targets',
        subtitle: 'Track execution milestones and long-term performance objectives.',
        navHint: 'Targets',
      },
    ],
  },
]

const navItems = computed(() => navSections.flatMap((section) => section.items))
const currentItem = computed(() =>
  navItems.value.find((item) => route.path === item.to || route.path.startsWith(`${item.to}/`)) ?? navItems.value[0]!
)
const showPageHero = computed(() => currentItem.value.to !== '/dashboard')
const compactHeroRoutes = new Set(['/trades', '/missed-trades'])
const useCompactHero = computed(() => compactHeroRoutes.has(route.path))
</script>

<template>
  <div class="app-shell">
    <div class="workspace-owner-badge motion-fade-scale">IZ | WAGMI</div>

    <div class="workspace-shell">
      <aside class="workspace-sidebar panel motion-fade-scale">
        <div class="workspace-sidebar-head">
          <div class="brand">
            <div class="brand-mark">
              <LineChart class="h-5 w-5" />
            </div>
            <div>
              <p class="brand-label">Professional Desk</p>
              <p class="brand-name">Execution Journal</p>
            </div>
          </div>
          <p class="workspace-sidebar-note">
            Structured for decision speed: risk first, execution second, review always.
          </p>
        </div>

        <nav class="workspace-nav">
          <section v-for="section in navSections" :key="section.label" class="workspace-nav-section">
            <p class="workspace-nav-label">{{ section.label }}</p>
            <RouterLink
              v-for="item in section.items"
              :key="item.to"
              :to="item.to"
              class="workspace-nav-link"
              active-class="is-active"
            >
              <span class="workspace-nav-icon">
                <component :is="item.icon" class="h-4 w-4" />
              </span>
              <span class="workspace-nav-copy">
                <span>{{ item.label }}</span>
                <small>{{ item.navHint }}</small>
              </span>
              <ChevronRight class="workspace-nav-arrow h-3.5 w-3.5" />
            </RouterLink>
          </section>
        </nav>

        <div class="workspace-sidebar-foot">
          <span class="pill pill-positive">
            <Sparkles class="h-3.5 w-3.5" />
            Process over outcome
          </span>
        </div>
      </aside>

      <section class="workspace-main">
        <header class="topbar motion-fade-scale">
          <div class="topbar-kicker">
            <span class="kicker-label">Workspace</span>
            <span class="topbar-active">{{ currentItem.label }}</span>
          </div>

          <div class="topbar-actions">
            <span v-if="isFallbackMode" class="topbar-fallback-indicator" title="Using local fallback data">
              <WifiOff class="h-3.5 w-3.5" />
              Local mode
              <small v-if="lastFallbackContext">{{ lastFallbackContext }}</small>
            </span>
            <button class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="uiStore.toggleTheme()">
              <Sun v-if="theme === 'dark'" class="h-4 w-4" />
              <Moon v-else class="h-4 w-4" />
              {{ theme === 'dark' ? 'Light' : 'Dark' }}
            </button>
          </div>
        </header>

        <div v-if="showPageHero" class="mt-8 motion-fade-scale" :class="{ 'page-hero-compact': useCompactHero }">
          <p class="page-kicker">Execution Suite</p>
          <h1 class="page-title">{{ currentItem.title }}</h1>
          <p class="page-subtitle">{{ currentItem.subtitle }}</p>
        </div>

        <main :class="showPageHero ? 'mt-6' : 'mt-2'">
          <RouterView v-slot="{ Component }">
            <Transition name="page" mode="out-in">
              <component :is="Component" :key="route.fullPath" />
            </Transition>
          </RouterView>
        </main>
      </section>
    </div>

    <nav class="mobile-nav">
      <RouterLink
        v-for="item in navItems"
        :key="`mobile-${item.to}`"
        :to="item.to"
        class="tab-link"
        active-class="is-active"
      >
        <component :is="item.icon" class="h-4 w-4" />
        <span>{{ item.label }}</span>
      </RouterLink>
    </nav>
  </div>
</template>
