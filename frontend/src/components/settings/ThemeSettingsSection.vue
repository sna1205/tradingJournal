<script setup lang="ts">
import { ref, watch } from 'vue'
import BaseSelect from '@/components/form/BaseSelect.vue'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import type { ThemeMode } from '@/stores/uiStore'

interface ThemeOption {
  value: ThemeMode
  label: string
}

const props = defineProps<{
  theme: ThemeMode
  options: ThemeOption[]
  saving?: boolean
}>()

const emit = defineEmits<{
  (event: 'change-theme', value: ThemeMode): void
}>()

const model = ref<ThemeMode>(props.theme)

watch(
  () => props.theme,
  (value) => {
    model.value = value
  }
)

function submit() {
  emit('change-theme', model.value)
}
</script>

<template>
  <GlassPanel>
    <header class="section-head">
      <div>
        <h2 class="section-title">Theme</h2>
        <p class="section-note">Theme preference syncs across devices under your account.</p>
      </div>
    </header>

    <BaseSelect
      v-model="model"
      label="Theme Mode"
      :options="options.map((option) => ({ label: option.label, value: option.value }))"
      :disabled="saving"
      hint="Applied instantly and saved to your profile."
    />

    <div class="mt-4">
      <button type="button" class="btn btn-primary px-4 py-2 text-sm" :disabled="saving" @click="submit">
        {{ saving ? 'Syncing...' : 'Sync Theme Preference' }}
      </button>
    </div>
  </GlassPanel>
</template>
