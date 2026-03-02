<script setup lang="ts">
import { computed } from 'vue'
import type { TradeChecklistReadiness } from '@/types/rules'

const props = defineProps<{
  readiness: TradeChecklistReadiness
}>()

const statusLabel = computed(() => {
  if (props.readiness.status === 'ready') return 'Ready'
  if (props.readiness.status === 'almost') return 'Almost'
  return 'Not Ready'
})

const statusClass = computed(() => {
  if (props.readiness.status === 'ready') return 'is-ready'
  if (props.readiness.status === 'almost') return 'is-almost'
  return 'is-not-ready'
})

const progressPct = computed(() => {
  if (props.readiness.total_required <= 0) return 100
  return Math.min(100, Math.max(0, (props.readiness.completed_required / props.readiness.total_required) * 100))
})

const missingCount = computed(() =>
  Math.max(0, props.readiness.total_required - props.readiness.completed_required)
)
</script>

<template>
  <header class="checklist-progress-header">
    <div class="checklist-progress-title-row">
      <div class="checklist-progress-copy">
        <p class="section-title">Pre-Trade Validation</p>
        <p class="checklist-progress-count">
          {{ readiness.completed_required }} / {{ readiness.total_required }} Required Completed
        </p>
      </div>

      <div class="checklist-progress-status-row">
        <span class="checklist-status-pill" :class="statusClass">{{ statusLabel }}</span>
        <p class="checklist-progress-meta">{{ missingCount }} missing</p>
      </div>
    </div>

    <div class="checklist-progress-track" aria-hidden="true">
      <div class="checklist-progress-fill" :style="{ width: `${progressPct}%` }" />
    </div>
  </header>
</template>

<style scoped>
.checklist-progress-header {
  display: grid;
  gap: 0.58rem;
  border: 1px solid color-mix(in srgb, var(--border) 26%, transparent 74%);
  border-radius: 14px;
  background:
    linear-gradient(138deg, color-mix(in srgb, var(--primary-soft) 34%, var(--panel) 66%), var(--panel)),
    var(--panel);
  padding: 0.74rem 0.8rem;
}

.checklist-progress-title-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.72rem;
}

.checklist-progress-copy {
  min-width: 0;
}

.checklist-progress-copy .section-title {
  margin: 0;
  font-size: 1.02rem;
}

.checklist-progress-count {
  margin: 0.2rem 0 0;
  font-size: 0.77rem;
  font-weight: 700;
  color: var(--muted);
}

.checklist-progress-status-row {
  display: grid;
  justify-items: end;
  gap: 0.22rem;
}

.checklist-status-pill {
  border: 1px solid color-mix(in srgb, var(--border) 34%, transparent 66%);
  border-radius: 999px;
  padding: 0.2rem 0.56rem;
  font-size: 0.73rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.checklist-status-pill.is-ready {
  color: color-mix(in srgb, var(--success) 78%, var(--text) 22%);
  border-color: color-mix(in srgb, var(--success) 46%, var(--border) 54%);
}

.checklist-status-pill.is-almost {
  color: color-mix(in srgb, var(--warning) 80%, var(--text) 20%);
  border-color: color-mix(in srgb, var(--warning) 44%, var(--border) 56%);
}

.checklist-status-pill.is-not-ready {
  color: color-mix(in srgb, var(--danger) 80%, var(--text) 20%);
  border-color: color-mix(in srgb, var(--danger) 46%, var(--border) 54%);
}

.checklist-progress-meta {
  margin: 0;
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--muted);
}

.checklist-progress-track {
  height: 6px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--border) 44%, transparent 56%);
  overflow: hidden;
}

.checklist-progress-fill {
  height: 100%;
  border-radius: 999px;
  background:
    linear-gradient(90deg, color-mix(in srgb, var(--primary) 88%, var(--text) 12%), color-mix(in srgb, var(--success) 80%, var(--text) 20%));
  transition: width 180ms ease-out;
}

@media (max-width: 1199px) {
  .checklist-progress-title-row {
    gap: 0.46rem;
  }

  .checklist-progress-copy .section-title {
    font-size: 0.94rem;
  }

  .checklist-progress-count {
    font-size: 0.72rem;
  }
}
</style>
