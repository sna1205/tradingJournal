<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import {
  BarChart3,
  Flag,
  LineChart,
  Moon,
  NotebookText,
  Sun,
  WalletCards,
} from 'lucide-vue-next'
import { storeToRefs } from 'pinia'
import BaseSelect from '@/components/form/BaseSelect.vue'
import { useAccountStore } from '@/stores/accountStore'
import { useUiStore } from '@/stores/uiStore'

const route = useRoute()
const uiStore = useUiStore()
const accountStore = useAccountStore()
const { theme } = storeToRefs(uiStore)
const { accounts, selectedAccountId } = storeToRefs(accountStore)

const navItems = [
  {
    label: 'Analytics',
    to: '/dashboard',
    icon: LineChart,
    title: 'Trade Journal + Analytics',
    subtitle: 'Review execution quality, performance patterns, and consistency at a glance.',
  },
  {
    label: 'Trades DB',
    to: '/trades',
    icon: NotebookText,
    title: 'Trades Database',
    subtitle: 'Search, review, and maintain your complete trade history.',
  },
  {
    label: 'Missed Trades DB',
    to: '/missed-trades',
    icon: BarChart3,
    title: 'Missed Trades Database',
    subtitle: 'Capture skipped setups and convert patterns into actionable improvements.',
  },
  {
    label: 'Accounts',
    to: '/accounts',
    icon: WalletCards,
    title: 'Accounts',
    subtitle: 'Manage trading accounts and monitor account-level equity and drawdown.',
  },
  {
    label: 'Milestones',
    to: '/milestones',
    icon: Flag,
    title: 'Milestones',
    subtitle: 'Track progress toward your discipline and performance targets.',
  },
]

const currentItem = computed(() =>
  navItems.find((item) => route.path === item.to || route.path.startsWith(`${item.to}/`)) ?? navItems[0]!
)

const accountOptions = computed(() => [
  {
    label: 'All Accounts (Portfolio)',
    value: '',
    subtitle: 'Aggregate analytics',
    badge: 'portfolio',
  },
  ...accounts.value.map((account) => ({
    label: account.name,
    value: String(account.id),
    subtitle: `${account.broker} - ${account.currency} ${Number(account.current_balance).toLocaleString()}${account.is_active ? '' : ' - inactive'}`,
    badge: account.account_type,
  })),
])

const selectedAccountModel = computed({
  get: () => (selectedAccountId.value === null ? '' : String(selectedAccountId.value)),
  set: (value: string) => {
    if (!value) {
      accountStore.setSelectedAccountId(null)
      return
    }

    accountStore.setSelectedAccountId(Number(value))
  },
})

onMounted(async () => {
  if (accounts.value.length > 0) return
  try {
    await accountStore.fetchAccounts()
  } catch {
    // Layout should stay functional even if accounts fail to load.
  }
})
</script>

<template>
  <div class="app-shell">
    <header class="topbar motion-fade-scale">
      <div class="brand">
        <div class="brand-mark">
          <LineChart class="h-5 w-5" />
        </div>
        <div>
          <p class="brand-label">Trading Workspace</p>
          <p class="brand-name">Trading Journal</p>
        </div>
      </div>

      <div class="topbar-actions">
        <BaseSelect
          v-model="selectedAccountModel"
          label="Account Scope"
          class="topbar-account-selector"
          searchable
          search-placeholder="Search account..."
          :options="accountOptions"
          size="sm"
        />

        <button class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="uiStore.toggleTheme()">
          <Sun v-if="theme === 'dark'" class="h-4 w-4" />
          <Moon v-else class="h-4 w-4" />
          {{ theme === 'dark' ? 'Light' : 'Dark' }}
        </button>
      </div>
    </header>

    <div class="mt-8 motion-fade-scale">
      <p class="page-kicker">Journal Suite</p>
      <h1 class="page-title">{{ currentItem.title }}</h1>
      <p class="page-subtitle">{{ currentItem.subtitle }}</p>
    </div>

    <nav class="tab-strip motion-fade-scale">
      <div class="tab-grid">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="tab-link"
          active-class="is-active"
        >
          <component :is="item.icon" class="h-4 w-4" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </div>
    </nav>

    <main class="mt-6">
      <RouterView v-slot="{ Component }">
        <Transition name="page" mode="out-in">
          <component :is="Component" :key="route.fullPath" />
        </Transition>
      </RouterView>
    </main>

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
