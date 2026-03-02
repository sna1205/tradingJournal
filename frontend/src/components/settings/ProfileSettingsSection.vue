<script setup lang="ts">
import { ref, watch } from 'vue'
import BaseInput from '@/components/form/BaseInput.vue'
import GlassPanel from '@/components/layout/GlassPanel.vue'

const props = defineProps<{
  name: string
  email: string
  timezone: string
  locale: string
  loading?: boolean
  saving?: boolean
}>()

const emit = defineEmits<{
  (
    event: 'save',
    payload: {
      profile_timezone: string
      profile_locale: string
    }
  ): void
}>()

const timezoneModel = ref(props.timezone)
const localeModel = ref(props.locale)

watch(
  () => props.timezone,
  (value) => {
    timezoneModel.value = value
  }
)

watch(
  () => props.locale,
  (value) => {
    localeModel.value = value
  }
)

function submit() {
  emit('save', {
    profile_timezone: timezoneModel.value.trim() || 'UTC',
    profile_locale: localeModel.value.trim() || 'en-US',
  })
}
</script>

<template>
  <GlassPanel>
    <header class="section-head">
      <div>
        <h2 class="section-title">Profile</h2>
        <p class="section-note">Identity and localization preferences used across this workspace.</p>
      </div>
    </header>

    <div class="settings-readonly-grid">
      <article class="panel p-3">
        <p class="kicker-label">Name</p>
        <p class="mt-1 font-semibold">{{ name || '-' }}</p>
      </article>
      <article class="panel p-3">
        <p class="kicker-label">Email</p>
        <p class="mt-1 font-semibold">{{ email || '-' }}</p>
      </article>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2">
      <BaseInput
        v-model="timezoneModel"
        label="Timezone"
        :disabled="loading || saving"
        hint="Example: UTC, America/New_York, Europe/London"
      />
      <BaseInput
        v-model="localeModel"
        label="Locale"
        :disabled="loading || saving"
        hint="Example: en-US, en-GB"
      />
    </div>

    <div class="mt-4">
      <button type="button" class="btn btn-primary px-4 py-2 text-sm" :disabled="loading || saving" @click="submit">
        {{ saving ? 'Saving...' : 'Save Profile Preferences' }}
      </button>
    </div>
  </GlassPanel>
</template>
