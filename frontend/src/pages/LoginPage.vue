<script setup lang="ts">
import { computed, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useRoute, useRouter } from 'vue-router'
import { Eye, EyeOff, LineChart, ShieldCheck, Sparkles, UserRound } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/authStore'

type AuthMode = 'login' | 'register'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const mode = ref<AuthMode>('login')
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const revealPassword = ref(false)
const revealPasswordConfirmation = ref(false)
const errorMessage = ref<string | null>(null)

const submitting = computed(() => authStore.loading)
const submitLabel = computed(() => (mode.value === 'login' ? 'Sign In' : 'Create Account'))
const passwordMismatch = computed(() =>
  mode.value === 'register' && passwordConfirmation.value !== '' && password.value !== passwordConfirmation.value
)
const canSubmit = computed(() => {
  const hasCore = email.value.trim() !== '' && password.value.trim() !== ''
  if (!hasCore) return false
  if (mode.value === 'login') return true
  return name.value.trim() !== '' && passwordConfirmation.value.trim() !== '' && !passwordMismatch.value
})
const heroTitle = computed(() =>
  mode.value === 'login' ? 'Back To The Desk' : 'Secure Journal Setup'
)
const heroSubtext = computed(() =>
  mode.value === 'login'
    ? 'Authenticate once and continue with isolated account-level analytics, reports, and checklists.'
    : 'Create a dedicated user workspace so all trades, accounts, and reports stay scoped to you.'
)

async function submit() {
  errorMessage.value = null
  if (!canSubmit.value) return

  try {
    if (mode.value === 'login') {
      await authStore.login(email.value, password.value)
    } else {
      await authStore.register(name.value, email.value, password.value, passwordConfirmation.value)
    }

    const redirectTarget = typeof route.query.redirect === 'string' && route.query.redirect !== ''
      ? route.query.redirect
      : '/dashboard'
    await router.replace(redirectTarget)
  } catch (error) {
    if (isAxiosError(error)) {
      const message = error.response?.data?.message
      if (typeof message === 'string' && message.trim() !== '') {
        errorMessage.value = message
        return
      }

      const firstError = Object.values(error.response?.data?.errors ?? {})
        .flat()
        .find((value) => typeof value === 'string')
      if (typeof firstError === 'string' && firstError.trim() !== '') {
        errorMessage.value = firstError
        return
      }
    }

    errorMessage.value = 'Authentication failed.'
  }
}

function switchMode(nextMode: AuthMode) {
  if (mode.value === nextMode) return
  mode.value = nextMode
  password.value = ''
  passwordConfirmation.value = ''
  revealPassword.value = false
  revealPasswordConfirmation.value = false
  errorMessage.value = null
}
</script>

<template>
  <div class="auth-shell">
    <div class="auth-shell-grid">
      <aside class="auth-hero">
        <div class="auth-brand-row">
          <span class="auth-brand-mark">
            <LineChart class="h-4 w-4" />
          </span>
          <span class="auth-brand-label">Trading Journal</span>
        </div>

        <h1 class="auth-hero-title">{{ heroTitle }}</h1>
        <p class="auth-hero-subtitle">{{ heroSubtext }}</p>

        <div class="auth-feature-list">
          <div class="auth-feature-item">
            <ShieldCheck class="h-4 w-4" />
            <span>User-scoped API access</span>
          </div>
          <div class="auth-feature-item">
            <Sparkles class="h-4 w-4" />
            <span>Dedicated reports and analytics</span>
          </div>
          <div class="auth-feature-item">
            <UserRound class="h-4 w-4" />
            <span>Ownership checks across resources</span>
          </div>
        </div>
      </aside>

      <section class="auth-panel">
        <header class="auth-panel-head">
          <p class="auth-kicker">Authentication</p>
          <h2 class="auth-title">{{ mode === 'login' ? 'Sign In' : 'Create Account' }}</h2>
          <p class="auth-subtitle">
            {{ mode === 'login' ? 'Use your credentials to continue.' : 'Register a secure profile to begin.' }}
          </p>
        </header>

        <div class="auth-mode-switch" role="tablist" aria-label="Authentication mode">
          <button
            type="button"
            class="auth-mode-btn"
            :class="{ active: mode === 'login' }"
            role="tab"
            :aria-selected="mode === 'login'"
            @click="switchMode('login')"
          >
            Login
          </button>
          <button
            type="button"
            class="auth-mode-btn"
            :class="{ active: mode === 'register' }"
            role="tab"
            :aria-selected="mode === 'register'"
            @click="switchMode('register')"
          >
            Register
          </button>
        </div>

        <form class="auth-form" @submit.prevent="submit">
          <label v-if="mode === 'register'" class="auth-field">
            <span class="auth-label">Name</span>
            <input v-model.trim="name" class="auth-input" type="text" autocomplete="name" required />
          </label>

          <label class="auth-field">
            <span class="auth-label">Email</span>
            <input v-model.trim="email" class="auth-input" type="email" autocomplete="email" required />
          </label>

          <label class="auth-field">
            <span class="auth-label">Password</span>
            <span class="auth-input-wrap">
              <input
                v-model="password"
                class="auth-input with-toggle"
                :type="revealPassword ? 'text' : 'password'"
                :autocomplete="mode === 'login' ? 'current-password' : 'new-password'"
                required
              />
              <button
                type="button"
                class="auth-visibility-btn"
                :aria-label="revealPassword ? 'Hide password' : 'Show password'"
                @click="revealPassword = !revealPassword"
              >
                <EyeOff v-if="revealPassword" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
              </button>
            </span>
          </label>

          <label v-if="mode === 'register'" class="auth-field">
            <span class="auth-label">Confirm Password</span>
            <span class="auth-input-wrap">
              <input
                v-model="passwordConfirmation"
                class="auth-input with-toggle"
                :class="{ 'auth-input-error': passwordMismatch }"
                :type="revealPasswordConfirmation ? 'text' : 'password'"
                autocomplete="new-password"
                required
              />
              <button
                type="button"
                class="auth-visibility-btn"
                :aria-label="revealPasswordConfirmation ? 'Hide confirmation password' : 'Show confirmation password'"
                @click="revealPasswordConfirmation = !revealPasswordConfirmation"
              >
                <EyeOff v-if="revealPasswordConfirmation" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
              </button>
            </span>
          </label>

          <p v-if="passwordMismatch" class="auth-inline-error">Passwords do not match.</p>
          <p v-if="errorMessage" class="auth-error">{{ errorMessage }}</p>

          <button type="submit" class="auth-submit" :disabled="submitting || !canSubmit">
            {{ submitting ? 'Please wait...' : submitLabel }}
          </button>
        </form>
      </section>
    </div>
  </div>
</template>

<style scoped>
.auth-shell {
  min-height: 100vh;
  padding: 1.5rem;
  background:
    radial-gradient(circle at 8% 8%, color-mix(in oklab, var(--primary) 20%, transparent) 0%, transparent 38%),
    radial-gradient(circle at 88% 86%, color-mix(in oklab, var(--warning) 18%, transparent) 0%, transparent 42%),
    var(--bg);
}

.auth-shell-grid {
  width: min(1120px, 100%);
  min-height: calc(100vh - 3rem);
  margin: 0 auto;
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
  align-items: stretch;
}

.auth-hero {
  position: relative;
  overflow: hidden;
  border-radius: 1.1rem;
  border: 1px solid color-mix(in srgb, var(--border) 72%, transparent 28%);
  background: linear-gradient(
    160deg,
    color-mix(in srgb, var(--panel-strong) 92%, transparent 8%),
    color-mix(in srgb, var(--panel-soft) 88%, transparent 12%)
  );
  box-shadow: var(--shadow-soft);
  padding: 1.4rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.auth-hero::after {
  content: '';
  position: absolute;
  right: -70px;
  bottom: -70px;
  width: 190px;
  height: 190px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--primary-soft) 56%, transparent 44%);
  pointer-events: none;
}

.auth-brand-row {
  display: inline-flex;
  width: fit-content;
  align-items: center;
  gap: 0.55rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--border) 72%, transparent 28%);
  background: color-mix(in srgb, var(--panel) 86%, transparent 14%);
  padding: 0.35rem 0.65rem;
}

.auth-brand-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.45rem;
  height: 1.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--primary-soft) 70%, transparent 30%);
  color: var(--primary);
}

.auth-brand-label {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted);
}

.auth-hero-title {
  margin-top: 0.2rem;
  font-size: clamp(1.55rem, 3vw, 2.35rem);
  line-height: 1.06;
}

.auth-hero-subtitle {
  margin: 0;
  max-width: 34ch;
  color: var(--muted);
  line-height: 1.4;
}

.auth-feature-list {
  margin-top: auto;
  display: grid;
  gap: 0.6rem;
}

.auth-feature-item {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  border: 1px solid color-mix(in srgb, var(--border) 74%, transparent 26%);
  background: color-mix(in srgb, var(--panel) 86%, transparent 14%);
  border-radius: 0.72rem;
  padding: 0.56rem 0.65rem;
  color: var(--text);
  font-size: 0.84rem;
}

.auth-feature-item svg {
  flex-shrink: 0;
  color: color-mix(in srgb, var(--primary) 75%, var(--text) 25%);
}

.auth-panel {
  border-radius: 1.1rem;
  border: 1px solid color-mix(in srgb, var(--border) 74%, transparent 26%);
  background: color-mix(in srgb, var(--panel) 90%, transparent 10%);
  box-shadow: var(--shadow-soft);
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
}

.auth-panel-head {
  margin-bottom: 0.95rem;
}

.auth-kicker {
  margin: 0;
  font-size: 0.71rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--muted);
}

.auth-title {
  margin-top: 0.42rem;
  font-size: clamp(1.32rem, 2.7vw, 1.9rem);
}

.auth-subtitle {
  margin-top: 0.3rem;
  color: var(--muted);
  font-size: 0.89rem;
}

.auth-mode-switch {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
  padding: 0.35rem;
  border-radius: 0.82rem;
  border: 1px solid color-mix(in srgb, var(--border) 74%, transparent 26%);
  background: color-mix(in srgb, var(--panel-soft) 88%, transparent 12%);
}

.auth-mode-btn {
  border-radius: 0.62rem;
  font-size: 0.83rem;
  font-weight: 700;
  color: var(--muted);
  padding: 0.54rem 0.6rem;
  transition: var(--transition-fast);
}

.auth-mode-btn.active {
  background: color-mix(in srgb, var(--primary-soft) 76%, transparent 24%);
  color: color-mix(in srgb, var(--primary) 72%, var(--text) 28%);
}

.auth-form {
  display: grid;
  gap: 0.78rem;
  margin-top: 0.95rem;
}

.auth-field {
  display: grid;
  gap: 0.4rem;
}

.auth-label {
  font-size: 0.77rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--muted);
}

.auth-input-wrap {
  position: relative;
  display: block;
}

.auth-input {
  width: 100%;
  border: 1px solid color-mix(in srgb, var(--border) 72%, transparent 28%);
  background: color-mix(in srgb, var(--panel) 84%, transparent 16%);
  color: var(--text);
  border-radius: 0.74rem;
  padding: 0.67rem 0.76rem;
  line-height: 1.35;
}

.auth-input.with-toggle {
  padding-right: 2.45rem;
}

.auth-input:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--primary) 44%, transparent 56%);
  outline-offset: 1px;
}

.auth-input-error {
  border-color: color-mix(in srgb, var(--danger) 74%, transparent 26%);
}

.auth-visibility-btn {
  position: absolute;
  top: 50%;
  right: 0.48rem;
  transform: translateY(-50%);
  border: 1px solid transparent;
  border-radius: 0.46rem;
  color: var(--muted);
  width: 1.8rem;
  height: 1.8rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.auth-visibility-btn:hover {
  color: var(--text);
  background: color-mix(in srgb, var(--panel-soft) 80%, transparent 20%);
}

.auth-inline-error {
  margin: 0;
  font-size: 0.81rem;
  color: color-mix(in srgb, var(--danger) 82%, var(--text) 18%);
}

.auth-error {
  margin: 0;
  border-radius: 0.72rem;
  border: 1px solid color-mix(in srgb, var(--danger) 36%, transparent 64%);
  background: color-mix(in srgb, var(--danger-soft) 68%, transparent 32%);
  color: color-mix(in srgb, var(--danger) 86%, var(--text) 14%);
  font-size: 0.84rem;
  padding: 0.57rem 0.67rem;
}

.auth-submit {
  margin-top: 0.18rem;
  width: 100%;
  border-radius: 0.76rem;
  background: color-mix(in srgb, var(--primary) 80%, var(--panel) 20%);
  color: white;
  font-weight: 700;
  letter-spacing: 0.01em;
  padding: 0.71rem 0.9rem;
  transition: var(--transition-fast);
}

.auth-submit:hover:not(:disabled) {
  filter: brightness(0.96);
}

.auth-submit:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

@media (min-width: 920px) {
  .auth-shell {
    padding: 2rem;
  }

  .auth-shell-grid {
    grid-template-columns: minmax(340px, 1.06fr) minmax(360px, 0.94fr);
    gap: 1.15rem;
    min-height: calc(100vh - 4rem);
  }

  .auth-hero {
    padding: 2rem 2rem 1.85rem;
  }

  .auth-panel {
    padding: 1.8rem;
    justify-content: center;
  }
}

@media (max-width: 520px) {
  .auth-shell {
    padding: 0.8rem;
  }

  .auth-shell-grid {
    min-height: calc(100vh - 1.6rem);
    gap: 0.8rem;
  }

  .auth-hero,
  .auth-panel {
    padding: 1rem;
    border-radius: 0.9rem;
  }

  .auth-hero-title {
    font-size: 1.45rem;
  }
}
</style>
