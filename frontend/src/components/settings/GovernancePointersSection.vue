<script setup lang="ts">
import { RouterLink } from 'vue-router'
import GlassPanel from '@/components/layout/GlassPanel.vue'
</script>

<template>
  <GlassPanel>
    <header class="section-head">
      <div>
        <h2 class="section-title">Governance Shortcuts</h2>
        <p class="section-note">Jump directly to rules and risk policy controls from one hub.</p>
      </div>
    </header>

    <div class="grid gap-3 md:grid-cols-2">
      <article class="panel p-3">
        <p class="kicker-label">Rule Scope Governance</p>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Keep one active rule set per scope and enforce strict mode only when rule definitions are stable.
        </p>
        <RouterLink to="/settings/rules" class="btn btn-ghost mt-3 inline-flex px-3 py-2 text-sm">
          Rules Builder
        </RouterLink>
      </article>
      <article class="panel p-3">
        <p class="kicker-label">Risk Policy Controls</p>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Review account-level challenge and risk policy values before increasing sizing or changing account routing.
        </p>
        <RouterLink to="/accounts" class="btn btn-ghost mt-3 inline-flex px-3 py-2 text-sm">
          Risk Policy (Accounts)
        </RouterLink>
      </article>
    </div>
  </GlassPanel>
</template>
