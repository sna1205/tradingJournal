<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import {
  LayoutDashboard,
  ClipboardList,
  SearchCheck,
  Goal,
  LineChart,
  Sparkles,
  WalletCards,
  ChevronRight,
  WifiOff,
} from 'lucide-vue-next'
import { storeToRefs } from 'pinia'
import { useUiStore, type ThemeMode } from '@/stores/uiStore'
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
        label: 'Execute Log',
        to: '/trades',
        icon: ClipboardList,
        title: 'Execute Log',
        subtitle: 'Track executed trades in a clean, focused log.',
        navHint: 'Executed trades',
      },
      {
        label: 'Missed Trade Log',
        to: '/missed-trades',
        icon: SearchCheck,
        title: 'Missed Trade Log',
        subtitle: 'Capture missed trades with concise context and notes.',
        navHint: 'Missed trades',
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

function onThemeSelect(event: Event) {
  const target = event.target
  if (!(target instanceof HTMLSelectElement)) return
  const value = target.value
  const isValid = uiStore.themeOptions.some((option) => option.value === value)
  if (!isValid) return
  uiStore.setTheme(value as ThemeMode)
}
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
          <div class="workspace-sidebar-theme">
            <p class="workspace-nav-label">Theme</p>
            <div class="theme-switcher theme-switcher-sidebar" aria-label="Theme switcher">
              <button
                v-for="option in uiStore.themeOptions"
                :key="`theme-${option.value}`"
                type="button"
                class="chip-btn theme-switcher-btn"
                :class="{ active: theme === option.value }"
                @click="uiStore.setTheme(option.value)"
              >
                {{ option.label }}
              </button>
            </div>
          </div>
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
            <div class="theme-switcher-mobile">
              <select class="field field-sm theme-mobile-select" :value="theme" aria-label="Theme" @change="onThemeSelect">
                <option v-for="option in uiStore.themeOptions" :key="`theme-mobile-${option.value}`" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
            </div>
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
