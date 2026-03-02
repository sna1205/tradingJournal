<script setup lang="ts">
import GlassPanel from '@/components/layout/GlassPanel.vue'

const props = defineProps<{
  enabled: boolean
  busy?: boolean
}>()

const emit = defineEmits<{
  (event: 'toggle', next: boolean): void
}>()

function toggle() {
  if (props.busy) return
  emit('toggle', !props.enabled)
}
</script>

<template>
  <GlassPanel>
    <header class="section-head">
      <div>
        <h2 class="section-title">Offline Mode</h2>
        <p class="section-note">Control whether sensitive trade/account drafts persist locally.</p>
      </div>
    </header>

    <article class="panel p-3">
      <p class="kicker-label">Sensitive Data Persistence</p>
      <p class="mt-1 text-sm text-[var(--muted)]">
        OFF keeps sensitive trade/account drafts in memory only. ON stores them in IndexedDB with scoped TTL and caps.
      </p>
      <p class="mt-2 text-sm">
        Current status:
        <strong>{{ enabled ? 'ON (IndexedDB persistence enabled)' : 'OFF (no sensitive persistence)' }}</strong>
      </p>
    </article>

    <div class="mt-4 flex flex-wrap gap-2">
      <button
        type="button"
        class="btn px-4 py-2 text-sm"
        :class="enabled ? 'btn-secondary' : 'btn-ghost'"
        :disabled="busy"
        @click="toggle"
      >
        {{ busy ? 'Updating...' : enabled ? 'Turn OFF' : 'Turn ON' }}
      </button>
    </div>
  </GlassPanel>
</template>
