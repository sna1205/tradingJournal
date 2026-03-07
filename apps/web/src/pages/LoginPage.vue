<script setup lang="ts">
import { computed, ref } from 'vue'
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

const email = ref('')
const password = ref('')
const revealPassword = ref(false)
const errorMessage = ref<string | null>(null)

const allowSelfRegister = computed(() => authStore.allowSelfRegister)
const submitting = computed(() => authStore.loading)
const canSubmit = computed(() => email.value.trim() !== '' && password.value.trim() !== '')

async function submit() {
  errorMessage.value = null
  if (!canSubmit.value) return

  try {
    await authStore.login(email.value, password.value)
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

const registerLink = computed(() => {
  const redirect = typeof route.query.redirect === 'string' && route.query.redirect !== ''
    ? route.query.redirect
    : ''

  return redirect !== ''
    ? { path: '/auth/register', query: { redirect } }
    : { path: '/auth/register' }
})
</script>

<template>
  <div class="auth-shell">
    <div class="auth-grid-overlay" />
    <div class="auth-glow auth-glow-a" />
    <div class="auth-glow auth-glow-b" />

    <div class="auth-shell-grid">
      <aside class="auth-stage">
        <div class="auth-brand-row">
          <span class="auth-brand-mark">
            <LineChart class="h-4 w-4" />
          </span>
          <span class="auth-brand-label">IZLedger</span>
        </div>

        <p class="auth-stage-kicker">Session Access</p>
        <h1 class="auth-stage-title">Back to your execution desk.</h1>
        <p class="auth-stage-subtitle">
          Continue with your account-scoped journals, checklists, and analytics to keep your process consistent.
        </p>

        <div class="auth-stage-metrics">
          <div class="metric-card">
            <small>Review Loop</small>
            <strong>Active</strong>
          </div>
          <div class="metric-card">
            <small>Theme</small>
            <strong>Dark</strong>
          </div>
        </div>

        <div class="auth-stage-note">
          <Sparkles class="h-4 w-4" />
          <span>Secure login keeps account data isolated by user workspace.</span>
        </div>
      </aside>

      <section class="auth-panel">
        <header class="auth-panel-head">
          <p class="auth-kicker">Authentication</p>
          <h2 class="auth-title">Sign In</h2>
          <p class="auth-subtitle">Enter your credentials to continue.</p>
        </header>

        <form class="auth-form" @submit.prevent="submit">
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
                autocomplete="current-password"
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

          <p v-if="errorMessage" class="auth-error">{{ errorMessage }}</p>

          <button type="submit" class="auth-submit" :disabled="submitting || !canSubmit">
            {{ submitting ? 'Please wait...' : 'Sign In' }}
          </button>

          <p v-if="allowSelfRegister" class="auth-switch-link">
            Need an account?
            <RouterLink :to="registerLink">Create account</RouterLink>
          </p>
        </form>
      </section>
    </div>
  </div>
</template>

<style scoped>
.auth-shell {
  position: relative;
  min-height: 100vh;
  overflow: hidden;
  padding: 1.2rem;
  background: var(--bg);
}

.auth-grid-overlay {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(to right, color-mix(in srgb, var(--border) 28%, transparent) 1px, transparent 1px),
    linear-gradient(to bottom, color-mix(in srgb, var(--border) 22%, transparent) 1px, transparent 1px);
  background-size: 40px 40px;
  mask-image: radial-gradient(circle at 50% 10%, black, transparent 72%);
  pointer-events: none;
}

.auth-glow {
  position: absolute;
  border-radius: 999px;
  pointer-events: none;
}

.auth-glow-a {
  width: 420px;
  height: 420px;
  top: -180px;
  left: -130px;
  background: radial-gradient(circle, color-mix(in srgb, var(--primary) 20%, transparent), transparent 68%);
}

.auth-glow-b {
  width: 420px;
  height: 420px;
  right: -150px;
  bottom: -180px;
  background: radial-gradient(circle, color-mix(in srgb, var(--warning) 18%, transparent), transparent 70%);
}

.auth-shell-grid {
  position: relative;
  z-index: 1;
  width: min(1120px, 100%);
  min-height: calc(100vh - 2.4rem);
  margin: 0 auto;
  display: grid;
  gap: 1rem;
  grid-template-columns: 1fr;
}

.auth-stage,
.auth-panel {
  border-radius: 1rem;
  border: 1px solid color-mix(in srgb, var(--border) 78%, transparent 22%);
  background: linear-gradient(
    165deg,
    color-mix(in srgb, var(--panel-strong) 88%, transparent 12%),
    color-mix(in srgb, var(--panel-soft) 88%, transparent 12%)
  );
  box-shadow: var(--shadow-soft);
}

.auth-stage {
  padding: 1.3rem;
  display: grid;
  align-content: start;
  gap: 0.9rem;
}

.auth-brand-row {
  display: inline-flex;
  align-items: center;
  gap: 0.58rem;
}

.auth-brand-mark {
  width: 1.9rem;
  height: 1.9rem;
  border-radius: 0.58rem;
  display: grid;
  place-items: center;
  background: linear-gradient(
    140deg,
    color-mix(in srgb, var(--primary) 86%, black 14%),
    color-mix(in srgb, var(--primary) 54%, var(--panel-strong) 46%)
  );
  color: color-mix(in srgb, var(--panel-strong) 82%, var(--text) 18%);
}

.auth-brand-label {
  font-size: 0.8rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--muted);
}

.auth-stage-kicker,
.auth-kicker {
  margin: 0;
  font-size: 0.71rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--primary) 70%, var(--text) 30%);
}

.auth-stage-title {
  margin: 0;
  font-size: clamp(1.8rem, 4.9vw, 2.6rem);
  line-height: 1.03;
}

.auth-stage-subtitle {
  margin: 0;
  max-width: 40ch;
  color: var(--muted);
  line-height: 1.62;
}

.auth-stage-metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.55rem;
}

.metric-card {
  border: 1px solid color-mix(in srgb, var(--border) 78%, transparent 22%);
  border-radius: 0.74rem;
  background: color-mix(in srgb, var(--panel) 74%, transparent 26%);
  padding: 0.62rem 0.7rem;
}

.metric-card small {
  display: block;
  margin-bottom: 0.2rem;
  font-size: 0.7rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.metric-card strong {
  font-size: 0.96rem;
}

.auth-stage-note {
  display: inline-flex;
  align-items: center;
  gap: 0.52rem;
  width: fit-content;
  border: 1px solid color-mix(in srgb, var(--border) 72%, transparent 28%);
  border-radius: 0.74rem;
  background: color-mix(in srgb, var(--panel-soft) 68%, transparent 32%);
  color: var(--muted);
  font-size: 0.84rem;
  padding: 0.5rem 0.7rem;
}

.auth-stage-note svg {
  color: var(--primary);
}

.auth-panel {
  padding: 1.15rem;
  display: grid;
  align-content: start;
  gap: 1rem;
}

.auth-panel-head {
  display: grid;
  gap: 0.28rem;
}

.auth-title {
  margin: 0;
  font-size: 1.45rem;
}

.auth-subtitle {
  margin: 0;
  color: var(--muted);
}

.auth-form {
  display: grid;
  gap: 0.8rem;
}

.auth-field {
  display: grid;
  gap: 0.34rem;
}

.auth-label {
  font-size: 0.82rem;
  color: var(--muted);
}

.auth-input-wrap {
  position: relative;
}

.auth-input {
  width: 100%;
  min-height: 2.72rem;
  border-radius: 0.72rem;
  border: 1px solid color-mix(in srgb, var(--border) 74%, transparent 26%);
  background: color-mix(in srgb, var(--panel-soft) 74%, transparent 26%);
  color: var(--text);
  padding: 0.64rem 0.8rem;
}

.auth-input.with-toggle {
  padding-right: 2.75rem;
}

.auth-input:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--primary) 42%, transparent 58%);
  outline-offset: 1px;
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
  color: var(--muted);
  display: grid;
  place-items: center;
}

.auth-visibility-btn:hover {
  border-color: color-mix(in srgb, var(--border) 78%, transparent 22%);
  background: color-mix(in srgb, var(--panel-soft) 56%, transparent 44%);
}

.auth-error {
  margin: 0;
  padding: 0.56rem 0.66rem;
  border-radius: 0.68rem;
  border: 1px solid color-mix(in srgb, var(--danger) 45%, transparent 55%);
  background: color-mix(in srgb, var(--danger) 14%, transparent 86%);
  color: color-mix(in srgb, var(--danger) 78%, var(--text) 22%);
  font-size: 0.84rem;
}

.auth-submit {
  min-height: 2.78rem;
  border: 0;
  border-radius: 0.8rem;
  font-weight: 700;
  color: color-mix(in srgb, var(--panel-strong) 88%, var(--text) 12%);
  background: linear-gradient(
    145deg,
    color-mix(in srgb, var(--primary) 86%, black 14%),
    color-mix(in srgb, var(--primary) 58%, var(--panel) 42%)
  );
  box-shadow: 0 10px 20px color-mix(in srgb, var(--primary) 24%, transparent 76%);
}

.auth-submit:hover:not(:disabled) {
  filter: brightness(1.05);
  transform: translateY(-1px);
}

.auth-submit:disabled {
  opacity: 0.58;
}

.auth-switch-link {
  margin: 0;
  font-size: 0.85rem;
  color: var(--muted);
}

.auth-switch-link a {
  color: var(--primary);
  text-decoration: none;
  font-weight: 600;
}

@media (min-width: 940px) {
  .auth-shell {
    padding: 2rem;
  }

  .auth-shell-grid {
    min-height: calc(100vh - 4rem);
    gap: 1.1rem;
    grid-template-columns: 1.05fr minmax(360px, 0.88fr);
  }

  .auth-stage,
  .auth-panel {
    padding: 1.4rem;
  }
}

@media (max-width: 640px) {
  .auth-shell {
    padding: 0.95rem;
  }

  .auth-shell-grid {
    min-height: calc(100vh - 1.9rem);
  }

  .auth-stage-metrics {
    grid-template-columns: 1fr;
  }
}
</style>
