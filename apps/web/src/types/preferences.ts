import type { ThemeMode } from '@/stores/uiStore'

export interface UserPreferences {
  user_id: number
  theme_mode: ThemeMode
  profile_timezone: string
  profile_locale: string
  updated_at: string | null
}
