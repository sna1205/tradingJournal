import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ThemeMode = 'light' | 'dark' | 'forest' | 'dawn'
export type ToastType = 'success' | 'error' | 'info'
export const THEME_OPTIONS: Array<{ value: ThemeMode; label: string }> = [
  { value: 'light', label: 'Light' },
  { value: 'dark', label: 'Dark' },
  { value: 'forest', label: 'Forest' },
  { value: 'dawn', label: 'Dawn' },
]

interface ToastItem {
  id: number
  type: ToastType
  title: string
  message?: string
  duration: number
}

interface ConfirmState {
  open: boolean
  title: string
  message: string
  confirmText: string
  cancelText: string
  danger: boolean
  resolve?: (value: boolean) => void
}

let toastId = 1

export const useUiStore = defineStore('ui', () => {
  const theme = ref<ThemeMode>('light')
  const toasts = ref<ToastItem[]>([])
  const confirm = ref<ConfirmState>({
    open: false,
    title: '',
    message: '',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    danger: false,
  })

  function isThemeMode(value: string | null): value is ThemeMode {
    if (!value) return false
    return THEME_OPTIONS.some((option) => option.value === value)
  }

  function applyTheme(mode: ThemeMode) {
    theme.value = mode
    document.documentElement.dataset.theme = mode
    localStorage.setItem('theme_mode', mode)
  }

  function setTheme(mode: ThemeMode) {
    applyTheme(mode)
  }

  function initTheme() {
    const saved = localStorage.getItem('theme_mode')
    const initial: ThemeMode = isThemeMode(saved) ? saved : 'light'
    applyTheme(initial)
  }

  function toggleTheme() {
    const currentIndex = THEME_OPTIONS.findIndex((option) => option.value === theme.value)
    const nextIndex = (currentIndex + 1) % THEME_OPTIONS.length
    applyTheme(THEME_OPTIONS[nextIndex]!.value)
  }

  function toast(payload: {
    type?: ToastType
    title: string
    message?: string
    duration?: number
  }) {
    const item: ToastItem = {
      id: toastId++,
      type: payload.type ?? 'info',
      title: payload.title,
      message: payload.message,
      duration: payload.duration ?? 2600,
    }

    toasts.value.push(item)
    window.setTimeout(() => removeToast(item.id), item.duration)
  }

  function removeToast(id: number) {
    toasts.value = toasts.value.filter((item) => item.id !== id)
  }

  function askConfirmation(options: {
    title: string
    message: string
    confirmText?: string
    cancelText?: string
    danger?: boolean
  }) {
    return new Promise<boolean>((resolve) => {
      confirm.value = {
        open: true,
        title: options.title,
        message: options.message,
        confirmText: options.confirmText ?? 'Confirm',
        cancelText: options.cancelText ?? 'Cancel',
        danger: options.danger ?? false,
        resolve,
      }
    })
  }

  function closeConfirmation(result: boolean) {
    confirm.value.resolve?.(result)
    confirm.value = {
      open: false,
      title: '',
      message: '',
      confirmText: 'Confirm',
      cancelText: 'Cancel',
      danger: false,
    }
  }

  return {
    theme,
    themeOptions: THEME_OPTIONS,
    toasts,
    confirm,
    initTheme,
    setTheme,
    toggleTheme,
    toast,
    removeToast,
    askConfirmation,
    closeConfirmation,
  }
})
