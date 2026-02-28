<script setup lang="ts">
import { RouterLink } from 'vue-router'
import GlassPanel from '@/components/layout/GlassPanel.vue'
</script>

<template>
  <GlassPanel>
    <header class="section-head">
      <div>
        <h2 class="section-title">Risk & Checklist Governance</h2>
        <p class="section-note">Operational pointers for policy, challenge controls, and checklist enforcement.</p>
      </div>
    </header>

    <div class="grid gap-3 md:grid-cols-2">
      <article class="panel p-3">
        <p class="kicker-label">Checklist Scope Governance</p>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Keep one active checklist per scope and enforce strict mode only when rule definitions are stable.
        </p>
        <RouterLink to="/settings/checklists" class="btn btn-ghost mt-3 inline-flex px-3 py-2 text-sm">
          Open Checklist Builder
        </RouterLink>
      </article>
      <article class="panel p-3">
        <p class="kicker-label">Risk Controls</p>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Review account-level challenge and risk policy values before increasing sizing or changing account routing.
        </p>
        <RouterLink to="/accounts" class="btn btn-ghost mt-3 inline-flex px-3 py-2 text-sm">
          Open Account Center
        </RouterLink>
      </article>
    </div>
  </GlassPanel>
</template>
