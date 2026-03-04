<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import ProfileSettingsSection from '@/components/settings/ProfileSettingsSection.vue'
import ThemeSettingsSection from '@/components/settings/ThemeSettingsSection.vue'
import SecuritySessionsSection from '@/components/settings/SecuritySessionsSection.vue'
import GovernancePointersSection from '@/components/settings/GovernancePointersSection.vue'
import OfflineModeSection from '@/components/settings/OfflineModeSection.vue'
import { useAuthStore } from '@/stores/authStore'
import { useUiStore } from '@/stores/uiStore'
import { useUserPreferencesStore } from '@/stores/userPreferencesStore'
import { isOfflineModeEnabled, setOfflineModeEnabled } from '@/services/localFallback'

const router = useRouter()
const authStore = useAuthStore()
const uiStore = useUiStore()
const preferencesStore = useUserPreferencesStore()

const profileSaving = ref(false)
const securityBusy = ref(false)
const lastRevokedSessions = ref<number | null>(null)
const offlineModeEnabled = ref(isOfflineModeEnabled())
const offlineModeBusy = ref(false)

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

async function changeOfflineMode(next: boolean) {
  offlineModeBusy.value = true
  try {
    offlineModeEnabled.value = await setOfflineModeEnabled(next)
    uiStore.toast({
      type: 'success',
      title: 'Offline mode updated',
      message: offlineModeEnabled.value
        ? 'Sensitive trade/account drafts will persist in IndexedDB.'
        : 'Sensitive trade/account drafts will no longer persist locally.',
    })
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Offline mode update failed',
      message: 'Could not update local persistence mode.',
    })
  } finally {
    offlineModeBusy.value = false
  }
}

onMounted(() => {
  offlineModeEnabled.value = isOfflineModeEnabled()
  void initialize()
})
</script>

<template>
  <div class="settings-hub space-y-4">
    <section class="settings-hub-header panel p-4 md:p-5">
      <p class="kicker-label">Settings Hub</p>
      <h1 class="section-title">Workspace Controls</h1>
      <p class="section-note">
        Profile, theme, offline mode, security sessions, and governance shortcuts are centralized here.
      </p>

      <nav class="settings-hub-nav" aria-label="Settings sections">
        <a class="chip-btn" href="#settings-profile">Profile</a>
        <a class="chip-btn" href="#settings-theme">Theme</a>
        <a class="chip-btn" href="#settings-offline">Offline Mode</a>
        <a class="chip-btn" href="#settings-security">Security</a>
        <a class="chip-btn" href="#settings-governance">Governance</a>
      </nav>
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
      <section id="settings-profile" class="scroll-mt-24">
        <ProfileSettingsSection
          :name="authStore.user?.name ?? ''"
          :email="authStore.user?.email ?? ''"
          :timezone="profileTimezone"
          :locale="profileLocale"
          :loading="preferencesStore.loading"
          :saving="profileSaving || preferencesStore.saving"
          @save="saveProfile"
        />
      </section>

      <section id="settings-theme" class="scroll-mt-24">
        <ThemeSettingsSection
          :theme="preferences?.theme_mode ?? uiStore.theme"
          :options="uiStore.themeOptions"
          :saving="preferencesStore.saving"
          @change-theme="changeTheme"
        />
      </section>

      <section id="settings-offline" class="scroll-mt-24">
        <OfflineModeSection
          :enabled="offlineModeEnabled"
          :busy="offlineModeBusy"
          @toggle="changeOfflineMode"
        />
      </section>

      <section id="settings-security" class="scroll-mt-24">
        <SecuritySessionsSection
          :busy="securityBusy || authStore.loading"
          :revoked-sessions="lastRevokedSessions"
          @logout-all="logoutOtherSessions"
          @logout-current="logoutCurrentSession"
        />
      </section>

      <section id="settings-governance" class="scroll-mt-24 xl:col-span-2">
        <GovernancePointersSection />
      </section>
    </div>
  </div>
</template>

<style scoped>
.settings-hub-header {
  display: grid;
  gap: 0.55rem;
}

.settings-hub-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.settings-hub-nav .chip-btn {
  text-decoration: none;
}
</style>
