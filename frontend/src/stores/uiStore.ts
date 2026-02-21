import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ThemeMode = 'dark' | 'light'
export type ToastType = 'success' | 'error' | 'info'

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

  function applyTheme(mode: ThemeMode) {
    theme.value = mode
    document.documentElement.dataset.theme = mode
    localStorage.setItem('theme_mode', mode)
  }

  function initTheme() {
    const saved = localStorage.getItem('theme_mode')
    const initial: ThemeMode = saved === 'dark' ? 'dark' : 'light'
    applyTheme(initial)
  }

  function toggleTheme() {
    applyTheme(theme.value === 'dark' ? 'light' : 'dark')
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
    toasts,
    confirm,
    initTheme,
    toggleTheme,
    toast,
    removeToast,
    askConfirmation,
    closeConfirmation,
  }
})
