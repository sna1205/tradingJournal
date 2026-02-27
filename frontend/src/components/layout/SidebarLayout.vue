<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import {
  LayoutDashboard,
  ClipboardList,
  SearchCheck,
  Goal,
  ListChecks,
  Calculator,
  LineChart,
  Plus,
  Sparkles,
  WalletCards,
  ChevronRight,
  ChevronDown,
  Menu,
  X,
  WifiOff,
  RefreshCw,
  ShieldAlert,
  SwatchBook,
  LogOut,
} from 'lucide-vue-next'
import { storeToRefs } from 'pinia'
import { useUiStore, type ThemeMode } from '@/stores/uiStore'
import { useSyncStatusStore } from '@/stores/syncStatusStore'
import { useAuthStore } from '@/stores/authStore'

const route = useRoute()
const router = useRouter()
const uiStore = useUiStore()
const syncStatusStore = useSyncStatusStore()
const authStore = useAuthStore()
const { theme } = storeToRefs(uiStore)
const {
  isFallbackMode,
  lastFallbackContext,
  queueSummary,
  pendingQueueCount,
  hasConflicts,
  conflictItems,
  syncing,
  lastSyncError,
} = storeToRefs(syncStatusStore)
const conflictModalOpen = ref(false)

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
      {
        label: 'Checklists',
        to: '/settings/checklists',
        icon: ListChecks,
        title: 'Pre-Trade Checklists',
        subtitle: 'Build and enforce custom pre-trade checklists by scope.',
        navHint: 'Builder',
      },
    ],
  },
  {
    label: 'Tools',
    items: [
      {
        label: 'Lots Calculate',
        to: '/tools/lots-calculate',
        icon: Calculator,
        title: 'Lots Calculate',
        subtitle: 'Calculate position size and risk before sending an order.',
        navHint: 'Sizing tool',
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
const hideGlobalFabOnRoutes = new Set(['trades-new', 'trades-edit', 'tools-lots-calculate', 'settings-checklists'])
const showGlobalFab = computed(() => !hideGlobalFabOnRoutes.has(String(route.name ?? '')))
const mobileThemeMenuOpen = ref(false)
const mobileNavOpen = ref(false)
const themeDropdownRef = ref<HTMLElement | null>(null)
const activeThemeLabel = computed(() =>
  uiStore.themeOptions.find((option) => option.value === theme.value)?.label ?? 'Theme'
)
const workspaceOwnerLabel = computed(() => {
  const name = authStore.user?.name?.trim()
  if (!name) {
    return 'Trader | WAGMI'
  }

  return `${name} | WAGMI`
})

function toggleMobileNav() {
  mobileThemeMenuOpen.value = false
  mobileNavOpen.value = !mobileNavOpen.value
}

function closeMobileNav() {
  mobileNavOpen.value = false
}

function toggleMobileThemeMenu() {
  mobileThemeMenuOpen.value = !mobileThemeMenuOpen.value
}

function chooseTheme(mode: ThemeMode) {
  uiStore.setTheme(mode)
  mobileThemeMenuOpen.value = false
}

async function logout() {
  await authStore.logout()
  await router.replace('/login')
}

function handleDocumentClick(event: MouseEvent) {
  if (!mobileThemeMenuOpen.value) return
  const root = themeDropdownRef.value
  if (!root) return
  const target = event.target
  if (target instanceof Node && root.contains(target)) return
  mobileThemeMenuOpen.value = false
}

function handleEscape(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  mobileThemeMenuOpen.value = false
  mobileNavOpen.value = false
  conflictModalOpen.value = false
}

function openConflictModal() {
  conflictModalOpen.value = true
}

function closeConflictModal() {
  conflictModalOpen.value = false
}

function runSyncNow() {
  void syncStatusStore.syncQueueNow()
}

function keepLocalDraft(queueId: string) {
  syncStatusStore.resolveConflictKeepLocal(queueId)
}

function acceptServerVersion(queueId: string) {
  syncStatusStore.resolveConflictAcceptServer(queueId)
}

function formatConflictPayload(payload: unknown): string {
  if (payload === null || payload === undefined) return '-'
  try {
    return JSON.stringify(payload, null, 2)
  } catch {
    return '-'
  }
}

onMounted(() => {
  document.addEventListener('click', handleDocumentClick)
  document.addEventListener('keydown', handleEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleDocumentClick)
  document.removeEventListener('keydown', handleEscape)
})

watch(
  () => route.fullPath,
  () => {
    mobileThemeMenuOpen.value = false
    mobileNavOpen.value = false
  }
)
</script>

<template>
  <div class="app-shell">
    <div class="workspace-owner-badge motion-fade-scale">{{ workspaceOwnerLabel }}</div>

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
          <button
            type="button"
            class="mobile-menu-trigger"
            :aria-expanded="mobileNavOpen"
            aria-controls="mobile-nav-drawer"
            aria-label="Toggle navigation menu"
            @click="toggleMobileNav"
          >
            <X v-if="mobileNavOpen" class="h-4 w-4" />
            <Menu v-else class="h-4 w-4" />
          </button>

          <div class="topbar-kicker">
            <span class="kicker-label">Workspace</span>
            <span class="topbar-active">{{ currentItem.label }}</span>
          </div>

          <div class="topbar-actions">
            <div ref="themeDropdownRef" class="theme-dropdown-floating" :class="{ open: mobileThemeMenuOpen }">
              <button
                type="button"
                class="workspace-theme-trigger"
                :title="`Theme: ${activeThemeLabel}`"
                :aria-label="`Choose theme (current: ${activeThemeLabel})`"
                @click="toggleMobileThemeMenu"
              >
                <SwatchBook class="h-4 w-4" />
                <span>{{ activeThemeLabel }}</span>
                <ChevronDown class="h-4 w-4" />
              </button>

              <div v-if="mobileThemeMenuOpen" class="workspace-theme-menu">
                <button
                  v-for="option in uiStore.themeOptions"
                  :key="`theme-float-${option.value}`"
                  type="button"
                  class="workspace-theme-option"
                  :class="{ active: theme === option.value }"
                  @click="chooseTheme(option.value)"
                >
                  {{ option.label }}
                </button>
              </div>
            </div>

            <button
              v-if="isFallbackMode || pendingQueueCount > 0 || hasConflicts"
              type="button"
              class="topbar-fallback-indicator"
              :title="hasConflicts ? 'Sync conflicts need review' : 'Offline draft mode active'"
              @click="openConflictModal"
            >
              <WifiOff class="h-3.5 w-3.5" />
              Offline drafts
              <small>
                {{ queueSummary.draft_local }} draft
                / {{ queueSummary.pending_sync }} pending
                / {{ queueSummary.conflict }} conflict
              </small>
            </button>

            <button
              v-if="isFallbackMode || pendingQueueCount > 0 || hasConflicts"
              type="button"
              class="workspace-theme-trigger"
              :disabled="syncing"
              :title="syncing ? 'Sync in progress' : 'Sync now'"
              @click="runSyncNow"
            >
              <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': syncing }" />
              <span>{{ syncing ? 'Syncing' : 'Sync now' }}</span>
            </button>

            <button type="button" class="workspace-theme-trigger" title="Logout" aria-label="Logout" @click="logout">
              <LogOut class="h-4 w-4" />
              <span>Logout</span>
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

    <RouterLink
      v-if="showGlobalFab"
      class="global-action-fab motion-fade-scale"
      to="/trades/new"
      title="New execution"
      aria-label="Add new execution"
    >
      <Plus class="h-7 w-7" />
    </RouterLink>

    <Transition name="mobile-nav-backdrop">
      <button
        v-if="mobileNavOpen"
        type="button"
        class="mobile-nav-backdrop"
        aria-label="Close navigation menu"
        @click="closeMobileNav"
      />
    </Transition>

    <Transition name="mobile-nav-drawer">
      <aside v-if="mobileNavOpen" id="mobile-nav-drawer" class="mobile-nav-drawer" aria-label="Primary navigation">
        <div class="mobile-nav-drawer-head">
          <p>Navigation</p>
          <button type="button" class="mobile-nav-close" aria-label="Close navigation menu" @click="closeMobileNav">
            <X class="h-4 w-4" />
          </button>
        </div>

        <nav class="mobile-nav-drawer-body">
          <section v-for="section in navSections" :key="`mobile-section-${section.label}`" class="mobile-nav-drawer-section">
            <p class="mobile-nav-drawer-label">{{ section.label }}</p>
            <RouterLink
              v-for="item in section.items"
              :key="`mobile-drawer-${item.to}`"
              :to="item.to"
              class="mobile-nav-drawer-link"
              active-class="is-active"
              @click="closeMobileNav"
            >
              <span class="mobile-nav-drawer-icon">
                <component :is="item.icon" class="h-4 w-4" />
              </span>
              <span class="mobile-nav-drawer-copy">
                <span>{{ item.label }}</span>
                <small>{{ item.navHint }}</small>
              </span>
            </RouterLink>
          </section>
        </nav>
      </aside>
    </Transition>

    <Transition name="fade">
      <div v-if="conflictModalOpen" class="app-modal-overlay fixed inset-0 flex items-center justify-center bg-black/45 px-4 backdrop-blur-sm" @click.self="closeConflictModal">
        <section class="panel sync-conflict-modal">
          <header class="section-head">
            <div>
              <p class="section-title">Sync Queue</p>
              <p class="section-note">
                {{ queueSummary.draft_local }} draft /
                {{ queueSummary.pending_sync }} pending /
                {{ queueSummary.conflict }} conflict
              </p>
            </div>
            <button type="button" class="btn btn-ghost p-2" aria-label="Close sync modal" @click="closeConflictModal">
              <X class="h-4 w-4" />
            </button>
          </header>

          <p v-if="lastFallbackContext" class="section-note mt-2">
            Last offline context: {{ lastFallbackContext }}
          </p>
          <p v-if="lastSyncError" class="field-error-text mt-2">{{ lastSyncError }}</p>

          <div v-if="conflictItems.length === 0" class="panel mt-3 p-3 text-sm">
            No conflicts in queue. Drafts will sync automatically when connectivity is stable.
          </div>

          <article v-for="item in conflictItems" :key="item.id" class="panel mt-3 p-3 text-sm sync-conflict-item">
            <p class="font-semibold inline-flex items-center gap-2">
              <ShieldAlert class="h-4 w-4 text-[var(--warning)]" />
              {{ item.entity }} {{ item.operation }} conflict
            </p>
            <p class="section-note mt-1">{{ item.conflict?.message ?? 'Conflict detected.' }}</p>

            <div class="sync-conflict-grid mt-2">
              <div>
                <p class="kicker-label">Local draft</p>
                <pre>{{ formatConflictPayload(item.conflict?.local_snapshot) }}</pre>
              </div>
              <div>
                <p class="kicker-label">Server snapshot</p>
                <pre>{{ formatConflictPayload(item.conflict?.server_snapshot) }}</pre>
              </div>
            </div>

            <div class="sync-conflict-actions mt-3">
              <button type="button" class="btn btn-ghost px-3 py-1.5 text-sm" @click="acceptServerVersion(item.id)">
                Accept server
              </button>
              <button type="button" class="btn btn-primary px-3 py-1.5 text-sm" @click="keepLocalDraft(item.id)">
                Keep local draft
              </button>
            </div>
          </article>
        </section>
      </div>
    </Transition>
  </div>
</template>
