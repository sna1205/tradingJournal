<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import ProfileSettingsSection from '@/components/settings/ProfileSettingsSection.vue'
import ThemeSettingsSection from '@/components/settings/ThemeSettingsSection.vue'
import SecuritySessionsSection from '@/components/settings/SecuritySessionsSection.vue'
import GovernancePointersSection from '@/components/settings/GovernancePointersSection.vue'
import { useAuthStore } from '@/stores/authStore'
import { useUiStore } from '@/stores/uiStore'
import { useUserPreferencesStore } from '@/stores/userPreferencesStore'

const router = useRouter()
const authStore = useAuthStore()
const uiStore = useUiStore()
const preferencesStore = useUserPreferencesStore()

const profileSaving = ref(false)
const securityBusy = ref(false)
const lastRevokedSessions = ref<number | null>(null)

const preferences = computed(() => preferencesStore.preferences)
const profileTimezone = computed(() => preferences.value?.profile_timezone ?? 'UTC')
const profileLocale = computed(() => preferences.value?.profile_locale ?? 'en-US')

async function initialize() {
  try {
    await preferencesStore.initialize(true)
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Could not load preferences',
      message: 'Using local defaults until the API is reachable.',
    })
  }
}

async function saveProfile(payload: { profile_timezone: string; profile_locale: string }) {
  profileSaving.value = true
  try {
    await preferencesStore.updatePreferences(payload)
    uiStore.toast({
      type: 'success',
      title: 'Profile preferences saved',
    })
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Save failed',
      message: 'Could not persist profile preferences.',
    })
  } finally {
    profileSaving.value = false
  }
}

async function changeTheme(themeMode: 'light' | 'dark' | 'forest' | 'dawn') {
  const synced = await preferencesStore.setThemePreference(themeMode)
  if (synced) {
    uiStore.toast({
      type: 'success',
      title: 'Theme synced',
      message: 'Theme preference was saved for this account.',
    })
    return
  }

  uiStore.toast({
    type: 'info',
    title: 'Theme applied locally',
    message: 'Preference sync is temporarily unavailable.',
  })
}

async function logoutOtherSessions() {
  securityBusy.value = true
  try {
    const result = await authStore.logoutAll()
    lastRevokedSessions.value = Number(result?.revoked_sessions ?? 0)
    uiStore.toast({
      type: 'success',
      title: 'Other sessions revoked',
      message: `Revoked ${lastRevokedSessions.value} session(s).`,
    })
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Security action failed',
      message: 'Could not revoke other sessions.',
    })
  } finally {
    securityBusy.value = false
  }
}

async function logoutCurrentSession() {
  await authStore.logout()
  await router.replace('/login')
}

onMounted(() => {
  void initialize()
})
</script>

<template>
  <div class="grid gap-4 xl:grid-cols-2">
    <ProfileSettingsSection
      :name="authStore.user?.name ?? ''"
      :email="authStore.user?.email ?? ''"
      :timezone="profileTimezone"
      :locale="profileLocale"
      :loading="preferencesStore.loading"
      :saving="profileSaving || preferencesStore.saving"
      @save="saveProfile"
    />

    <ThemeSettingsSection
      :theme="preferences?.theme_mode ?? uiStore.theme"
      :options="uiStore.themeOptions"
      :saving="preferencesStore.saving"
      @change-theme="changeTheme"
    />

    <SecuritySessionsSection
      :busy="securityBusy || authStore.loading"
      :revoked-sessions="lastRevokedSessions"
      @logout-all="logoutOtherSessions"
      @logout-current="logoutCurrentSession"
    />

    <GovernancePointersSection />
  </div>
</template>
