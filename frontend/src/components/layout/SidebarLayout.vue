<script setup lang="ts">
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { BarChart3, Flag, LayoutDashboard, Moon, NotebookText, Sun } from 'lucide-vue-next'
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/uiStore'

const route = useRoute()
const uiStore = useUiStore()
const { theme } = storeToRefs(uiStore)

const navItems = [
  { label: 'Dashboard', to: '/dashboard', icon: LayoutDashboard },
  { label: 'Trades', to: '/trades', icon: NotebookText },
  { label: 'Missed Trades', to: '/missed-trades', icon: BarChart3 },
  { label: 'Milestones', to: '/milestones', icon: Flag },
]
</script>

<template>
  <div class="relative min-h-screen overflow-hidden text-slate-100">
    <div class="pointer-events-none absolute inset-0 opacity-70">
      <div class="absolute left-10 top-10 h-64 w-64 rounded-full bg-emerald-500/15 blur-3xl" />
      <div class="absolute -top-12 right-20 h-72 w-72 rounded-full bg-sky-400/10 blur-3xl" />
      <div class="absolute bottom-0 left-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-slate-400/10 blur-3xl" />
    </div>

    <div class="relative mx-auto flex min-h-screen w-full max-w-[1440px] gap-6 px-4 py-4 md:px-8 md:py-6">
      <aside class="glass-card hidden w-72 rounded-2xl p-6 lg:block">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Trading Intelligence</p>
            <h1 class="mt-2 text-2xl font-bold">Journal Suite</h1>
          </div>
          <button
            class="rounded-xl border border-slate-600/80 p-2 transition-all duration-200 ease-in-out hover:-translate-y-0.5 hover:bg-slate-700/40"
            @click="uiStore.toggleTheme()"
          >
            <Sun v-if="theme === 'dark'" class="h-4 w-4 text-amber-300" />
            <Moon v-else class="h-4 w-4 text-slate-700" />
          </button>
        </div>

        <nav class="mt-8 space-y-2">
          <RouterLink
            v-for="item in navItems"
            :key="item.to"
            :to="item.to"
            class="group flex items-center gap-3 rounded-2xl border border-slate-700/75 px-4 py-3 text-sm font-semibold text-slate-200 transition-all duration-200 ease-in-out hover:-translate-y-0.5 hover:border-emerald-400/50 hover:bg-emerald-500/10"
            active-class="!border-emerald-400/75 !bg-emerald-500/15 !text-emerald-100"
          >
            <component :is="item.icon" class="h-4 w-4 transition-transform duration-200 ease-out group-hover:scale-110" />
            {{ item.label }}
          </RouterLink>
        </nav>
      </aside>

      <div class="flex min-w-0 flex-1 flex-col gap-4 pb-20 lg:pb-0">
        <header class="glass-card rounded-2xl px-4 py-4 lg:hidden">
          <div class="mb-4 flex items-center justify-between">
            <h1 class="text-lg font-bold">Journal Suite</h1>
            <button
              class="rounded-xl border border-slate-600/80 p-2 transition-all duration-200 ease-in-out hover:-translate-y-0.5 hover:bg-slate-700/40"
              @click="uiStore.toggleTheme()"
            >
              <Sun v-if="theme === 'dark'" class="h-4 w-4 text-amber-300" />
              <Moon v-else class="h-4 w-4 text-slate-700" />
            </button>
          </div>
          <p class="text-xs uppercase tracking-[0.24em] text-slate-400">TradingView x Notion Hybrid Workspace</p>
        </header>

        <main class="flex-1">
          <RouterView v-slot="{ Component }">
            <Transition name="page" mode="out-in">
              <component :is="Component" :key="route.fullPath" />
            </Transition>
          </RouterView>
        </main>
      </div>
    </div>

    <nav class="glass-card fixed bottom-3 left-3 right-3 z-50 grid grid-cols-4 gap-2 rounded-2xl p-2 lg:hidden">
      <RouterLink
        v-for="item in navItems"
        :key="`mobile-${item.to}`"
        :to="item.to"
        class="group flex flex-col items-center gap-1 rounded-xl px-2 py-2 text-[11px] font-semibold text-slate-300 transition-all duration-200 ease-in-out hover:bg-slate-800/65"
        active-class="!bg-emerald-500/20 !text-emerald-100"
      >
        <component :is="item.icon" class="h-4 w-4 transition-transform duration-200 ease-out group-hover:scale-110" />
        {{ item.label }}
      </RouterLink>
    </nav>
  </div>
</template>
