<script setup lang="ts">
import { ref } from 'vue'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseSelect from '@/components/form/BaseSelect.vue'
import BaseDateTime from '@/components/form/BaseDateTime.vue'
import { useUiStore } from '@/stores/uiStore'

const uiStore = useUiStore()
const pair = ref('EURUSD')
const direction = ref('buy')
const timestamp = ref('2026-02-22T10:35')
const notes = ref('Example execution notes')
const directionOptions = [
  { label: 'Long', value: 'buy' },
  { label: 'Short', value: 'sell' },
]

function openConfirmation() {
  void uiStore.askConfirmation({
    title: 'Delete execution entry?',
    message: 'This snapshot validates modal spacing and layering.',
    confirmText: 'Delete',
    danger: true,
  })
}
</script>

<template>
  <div class="space-y-5 visual-regression-page">
    <GlassPanel data-testid="visual-form">
      <div class="section-head">
        <h2 class="section-title">Visual Regression Harness</h2>
        <p class="section-note">Used only for UI screenshot tests.</p>
      </div>
      <div class="grid grid-premium md:grid-cols-2">
        <div data-testid="visual-pair"><BaseInput v-model="pair" label="Pair" /></div>
        <div data-testid="visual-direction">
          <BaseSelect
            v-model="direction"
            label="Direction"
            searchable
            :options="directionOptions"
            search-placeholder="Search direction..."
          />
        </div>
        <div data-testid="visual-date"><BaseDateTime v-model="timestamp" label="Date" /></div>
        <div data-testid="visual-notes"><BaseInput v-model="notes" label="Notes" multiline :rows="3" /></div>
      </div>
      <div class="mt-4 flex items-center gap-2">
        <button type="button" class="btn btn-primary px-4 py-2 text-sm">Save</button>
        <button type="button" class="btn btn-secondary px-4 py-2 text-sm" data-testid="open-confirm" @click="openConfirmation">
          Open Confirm Modal
        </button>
      </div>
    </GlassPanel>
  </div>
</template>
