<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import {
  BarChart3,
  Flag,
  LineChart,
  Moon,
  NotebookText,
  Sun,
} from 'lucide-vue-next'
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/uiStore'

const route = useRoute()
const uiStore = useUiStore()
const { theme } = storeToRefs(uiStore)

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
    label: 'Milestones',
    to: '/milestones',
    icon: Flag,
    title: 'Milestones',
    subtitle: 'Track progress toward your discipline and performance targets.',
  },
]

const currentItem = computed(() =>
  navItems.find((item) => item.to === route.path) ?? navItems[0]!
)
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

      <button class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="uiStore.toggleTheme()">
        <Sun v-if="theme === 'dark'" class="h-4 w-4" />
        <Moon v-else class="h-4 w-4" />
        {{ theme === 'dark' ? 'Light' : 'Dark' }}
      </button>
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
