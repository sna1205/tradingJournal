<script setup lang="ts">
import GlassPanel from '@/components/layout/GlassPanel.vue'

const props = defineProps<{
  busy?: boolean
  revokedSessions?: number | null
}>()

const emit = defineEmits<{
  (event: 'logout-all'): void
  (event: 'logout-current'): void
}>()
</script>

<template>
  <GlassPanel>
    <header class="section-head">
      <div>
        <h2 class="section-title">Security (Sessions)</h2>
        <p class="section-note">Session-level controls to lock down account access quickly.</p>
      </div>
    </header>

    <article class="panel p-3">
      <p class="kicker-label">Session Governance</p>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Use "Logout other sessions" to revoke every active session except this device.
      </p>
      <p v-if="typeof revokedSessions === 'number'" class="mt-2 text-sm">
        Last action revoked <strong>{{ revokedSessions }}</strong> session(s).
      </p>
    </article>

    <div class="mt-4 flex flex-wrap gap-2">
      <button type="button" class="btn btn-secondary px-4 py-2 text-sm" :disabled="busy" @click="emit('logout-all')">
        {{ busy ? 'Revoking...' : 'Logout Other Sessions' }}
      </button>
      <button type="button" class="btn btn-ghost px-4 py-2 text-sm" :disabled="busy" @click="emit('logout-current')">
        Logout Current Session
      </button>
    </div>
  </GlassPanel>
</template>
