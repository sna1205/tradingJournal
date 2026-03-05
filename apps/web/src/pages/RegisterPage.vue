<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Eye, EyeOff, LineChart, Sparkles } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/authStore'
import { useUserPreferencesStore } from '@/stores/userPreferencesStore'
import { normalizeApiError } from '@/utils/apiError'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const userPreferencesStore = useUserPreferencesStore()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const revealPassword = ref(false)
const revealPasswordConfirmation = ref(false)
const errorMessage = ref<string | null>(null)

const submitting = computed(() => authStore.loading)
const allowSelfRegister = computed(() => authStore.allowSelfRegister)
const passwordMismatch = computed(() =>
  passwordConfirmation.value !== '' && password.value !== passwordConfirmation.value
)
const canSubmit = computed(() =>
  name.value.trim() !== ''
  && email.value.trim() !== ''
  && password.value.trim() !== ''
  && passwordConfirmation.value.trim() !== ''
  && !passwordMismatch.value
)

watch(allowSelfRegister, (enabled) => {
  if (!enabled) {
    void router.replace('/auth/login')
  }
}, { immediate: true })

async function submit() {
  errorMessage.value = null
  if (!canSubmit.value) return

  try {
    await authStore.register(name.value, email.value, password.value, passwordConfirmation.value)
    await userPreferencesStore.initialize(true)

    const redirectTarget = typeof route.query.redirect === 'string' && route.query.redirect !== ''
      ? route.query.redirect
      : '/dashboard'
    await router.replace(redirectTarget)
  } catch (error) {
    if (isAxiosError(error)) {
      if (!error.response) {
        errorMessage.value = error.code === 'ECONNABORTED'
          ? 'Authentication request timed out. Please try again.'
          : 'Unable to reach the API. Verify production API URL/proxy and CORS settings.'
        return
      }

      const payload = error.response?.data
      if (typeof payload === 'string' && /<(!doctype|html)/i.test(payload.trim())) {
        errorMessage.value = 'API returned HTML instead of JSON. Verify Railway API_UPSTREAM_URL and backend route configuration.'
        return
      }

      errorMessage.value = normalizeApiError(error).message
      return
    }

    errorMessage.value = 'Authentication failed.'
  }
}

const loginLink = computed(() => {
  const redirect = typeof route.query.redirect === 'string' && route.query.redirect !== ''
    ? route.query.redirect
    : ''

  return redirect !== ''
    ? { path: '/auth/login', query: { redirect } }
    : { path: '/auth/login' }
})
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

        <h1 class="auth-hero-title">Secure Journal Setup</h1>
        <p class="auth-hero-subtitle">
          Create a dedicated user workspace so all trades, accounts, and reports stay scoped to you.
        </p>

        <div class="auth-feature-list">
          <div class="auth-feature-item">
            <Sparkles class="h-4 w-4" />
            <span>Dedicated reports and analytics</span>
          </div>
        </div>
      </aside>

      <section class="auth-panel">
        <header class="auth-panel-head">
          <p class="auth-kicker">Authentication</p>
          <h2 class="auth-title">Create Account</h2>
          <p class="auth-subtitle">Register a secure profile to begin.</p>
        </header>

        <form class="auth-form" @submit.prevent="submit">
          <label class="auth-field">
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
                autocomplete="new-password"
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

          <label class="auth-field">
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
            {{ submitting ? 'Please wait...' : 'Create Account' }}
          </button>

          <p class="auth-switch-link">
            Already have an account?
            <RouterLink :to="loginLink">Sign in</RouterLink>
          </p>
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
  inset: auto -18% -26% auto;
  width: 55%;
  aspect-ratio: 1;
  border-radius: 999px;
  background: radial-gradient(circle, color-mix(in srgb, var(--primary) 28%, transparent 72%), transparent 68%);
  pointer-events: none;
}

.auth-brand-row {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
}

.auth-brand-mark {
  width: 1.8rem;
  height: 1.8rem;
  border-radius: 0.6rem;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--primary) 24%, transparent 76%);
  color: color-mix(in srgb, var(--primary) 76%, white 24%);
}

.auth-brand-label {
  font-size: 0.82rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-soft);
}

.auth-hero-title {
  margin: 0;
  font-size: clamp(1.7rem, 4.6vw, 2.4rem);
  line-height: 1.05;
}

.auth-hero-subtitle {
  margin: 0;
  color: var(--text-muted);
  max-width: 36ch;
}

.auth-feature-list {
  display: grid;
  gap: 0.6rem;
}

.auth-feature-item {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  width: fit-content;
  padding: 0.5rem 0.7rem;
  border-radius: 0.7rem;
  border: 1px solid color-mix(in srgb, var(--border) 64%, transparent 36%);
  background: color-mix(in srgb, var(--panel-soft) 70%, transparent 30%);
  color: var(--text-soft);
  font-size: 0.85rem;
}

.auth-feature-item svg {
  color: var(--primary);
}

.auth-panel {
  border-radius: 1.1rem;
  border: 1px solid color-mix(in srgb, var(--border) 72%, transparent 28%);
  background: color-mix(in srgb, var(--panel) 94%, transparent 6%);
  box-shadow: var(--shadow-soft);
  padding: 1.2rem;
  display: grid;
  gap: 1rem;
}

.auth-panel-head {
  display: grid;
  gap: 0.3rem;
}

.auth-kicker {
  margin: 0;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  color: var(--text-soft);
}

.auth-title {
  margin: 0;
  font-size: 1.4rem;
}

.auth-subtitle {
  margin: 0;
  color: var(--text-muted);
}

.auth-form {
  display: grid;
  gap: 0.85rem;
}

.auth-field {
  display: grid;
  gap: 0.35rem;
}

.auth-label {
  font-size: 0.84rem;
  color: var(--text-soft);
}

.auth-input-wrap {
  position: relative;
}

.auth-input {
  width: 100%;
  min-height: 2.7rem;
  border-radius: 0.72rem;
  border: 1px solid color-mix(in srgb, var(--border) 72%, transparent 28%);
  background: color-mix(in srgb, var(--panel-soft) 78%, transparent 22%);
  color: var(--text);
  padding: 0.62rem 0.8rem;
}

.auth-input.with-toggle {
  padding-right: 2.8rem;
}

.auth-input:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--primary) 44%, transparent 56%);
  outline-offset: 1px;
}

.auth-input-error {
  border-color: color-mix(in srgb, var(--danger) 58%, transparent 42%);
}

.auth-visibility-btn {
  position: absolute;
  top: 50%;
  right: 0.48rem;
  transform: translateY(-50%);
  width: 2rem;
  height: 2rem;
  border-radius: 0.55rem;
  border: 1px solid transparent;
  background: transparent;
  color: var(--text-muted);
  display: grid;
  place-items: center;
}

.auth-visibility-btn:hover {
  border-color: color-mix(in srgb, var(--border) 78%, transparent 22%);
  background: color-mix(in srgb, var(--panel-soft) 58%, transparent 42%);
}

.auth-inline-error {
  margin: 0;
  color: color-mix(in srgb, var(--danger) 78%, white 22%);
  font-size: 0.84rem;
}

.auth-error {
  margin: 0;
  padding: 0.56rem 0.66rem;
  border-radius: 0.68rem;
  border: 1px solid color-mix(in srgb, var(--danger) 45%, transparent 55%);
  background: color-mix(in srgb, var(--danger) 14%, transparent 86%);
  color: color-mix(in srgb, var(--danger) 78%, white 22%);
  font-size: 0.84rem;
}

.auth-submit {
  min-height: 2.75rem;
  border: none;
  border-radius: 0.8rem;
  background: linear-gradient(
    145deg,
    color-mix(in srgb, var(--primary) 86%, black 14%),
    color-mix(in srgb, var(--primary) 56%, var(--panel) 44%)
  );
  color: white;
  font-weight: 700;
}

.auth-submit:hover:not(:disabled) {
  filter: brightness(1.05);
}

.auth-submit:disabled {
  opacity: 0.58;
}

.auth-switch-link {
  margin: 0;
  font-size: 0.86rem;
  color: var(--text-muted);
}

.auth-switch-link a {
  color: var(--primary);
  text-decoration: none;
  font-weight: 600;
}

@media (min-width: 920px) {
  .auth-shell {
    padding: 2rem;
  }

  .auth-shell-grid {
    min-height: calc(100vh - 4rem);
    grid-template-columns: 1fr minmax(350px, 0.86fr);
    gap: 1.15rem;
  }

  .auth-hero,
  .auth-panel {
    padding: 1.45rem;
  }
}

@media (max-width: 640px) {
  .auth-shell {
    padding: 0.95rem;
  }

  .auth-shell-grid {
    min-height: calc(100vh - 1.9rem);
  }
}
</style>
