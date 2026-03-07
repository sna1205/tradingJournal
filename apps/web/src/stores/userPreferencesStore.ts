import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import { THEME_OPTIONS, type ThemeMode, useUiStore } from '@/stores/uiStore'
import { useAuthStore } from '@/stores/authStore'
import type { UserPreferences } from '@/types/preferences'

const THEME_VALUES = new Set<ThemeMode>(THEME_OPTIONS.map((option) => option.value))

function isThemeMode(value: unknown): value is ThemeMode {
  return typeof value === 'string' && THEME_VALUES.has(value as ThemeMode)
}

function normalizePreferences(payload: unknown, fallbackUserId = 0): UserPreferences {
  const source = (payload && typeof payload === 'object' ? payload : {}) as Record<string, unknown>
  const userId = Number(source.user_id ?? fallbackUserId)
  const themeValue = source.theme_mode
  const timezoneValue = source.profile_timezone
  const localeValue = source.profile_locale
  const updatedAtValue = source.updated_at

  return {
    user_id: Number.isFinite(userId) ? userId : fallbackUserId,
    theme_mode: isThemeMode(themeValue) ? themeValue : 'dark',
    profile_timezone: typeof timezoneValue === 'string' && timezoneValue.trim() !== ''
      ? timezoneValue
      : 'UTC',
    profile_locale: typeof localeValue === 'string' && localeValue.trim() !== ''
      ? localeValue
      : 'en-US',
    updated_at: typeof updatedAtValue === 'string' && updatedAtValue.trim() !== ''
      ? updatedAtValue
      : null,
  }
}

export const useUserPreferencesStore = defineStore('userPreferences', () => {
  const preferences = ref<UserPreferences | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const initialized = ref(false)
  const loadedUserId = ref<number | null>(null)

  const themeMode = computed<ThemeMode>(() => preferences.value?.theme_mode ?? 'dark')

  async function initialize(force = false) {
    const authStore = useAuthStore()
    const userId = authStore.user?.id ?? null

    if (!authStore.isAuthenticated || userId === null) {
      initialized.value = true
      loadedUserId.value = null
      preferences.value = null
      return
    }

    if (!force && initialized.value && loadedUserId.value === userId) {
      return
    }

    loading.value = true
    try {
      const fetched = await fetchPreferences()
      applyThemeFromPreferences(fetched)
    } finally {
      loading.value = false
      initialized.value = true
      loadedUserId.value = userId
    }
  }

  async function fetchPreferences() {
    const authStore = useAuthStore()
    const userId = authStore.user?.id ?? 0
    const { data } = await api.get<UserPreferences>('/user/preferences')
    const normalized = normalizePreferences(data, userId)
    preferences.value = normalized
    return normalized
  }

  async function updatePreferences(patch: Partial<Pick<UserPreferences, 'theme_mode' | 'profile_timezone' | 'profile_locale'>>) {
    const authStore = useAuthStore()
    const userId = authStore.user?.id ?? 0
    if (!authStore.isAuthenticated || userId === 0) {
      const current = preferences.value ?? normalizePreferences({}, userId)
      preferences.value = {
        ...current,
        ...patch,
      }
      if (patch.theme_mode && isThemeMode(patch.theme_mode)) {
        applyThemeFromPreferences(preferences.value)
      }
      return preferences.value
    }

    saving.value = true
    try {
      const { data } = await api.put<UserPreferences>('/user/preferences', patch)
      const normalized = normalizePreferences(data, userId)
      preferences.value = normalized
      if (patch.theme_mode && isThemeMode(patch.theme_mode)) {
        applyThemeFromPreferences(normalized)
      }
      return normalized
    } finally {
      saving.value = false
    }
  }

  async function setThemePreference(mode: ThemeMode): Promise<boolean> {
    applyThemeFromPreferences({ theme_mode: mode })

    try {
      await updatePreferences({ theme_mode: mode })
      return true
    } catch {
      // Keep local theme responsive even when remote preference sync fails.
      return false
    }
  }

  function applyThemeFromPreferences(next: Pick<UserPreferences, 'theme_mode'>) {
    if (!isThemeMode(next.theme_mode)) return
    const uiStore = useUiStore()
    uiStore.setTheme(next.theme_mode)
  }

  return {
    preferences,
    loading,
    saving,
    initialized,
    themeMode,
    initialize,
    fetchPreferences,
    updatePreferences,
    setThemePreference,
  }
})
