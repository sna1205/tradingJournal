<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

const scrolled = ref(false)

const nav = [
  { label: 'Platform', href: '#platform' },
  { label: 'Method', href: '#method' },
  { label: 'FAQ', href: '#faq' },
]

const tape = [
  'Pre-trade gate',
  'Checklist snapshots',
  'Missed-trade journal',
  'Execution scoring',
  'Cross-account reviews',
  'CSV exports',
  'Offline queue',
  'Risk policy context',
  'Pre-trade gate',
  'Checklist snapshots',
  'Missed-trade journal',
  'Execution scoring',
  'Cross-account reviews',
  'CSV exports',
  'Offline queue',
  'Risk policy context',
]

const cards = [
  {
    title: 'Before the bell',
    text: 'Open your checklist, lock risk limits, and define invalidation before the first click.',
  },
  {
    title: 'During execution',
    text: 'Capture setup intent, legs, and psychology in structured fields while context is fresh.',
  },
  {
    title: 'After close',
    text: 'Review discipline trends and rule breaks so tomorrow starts with an updated plan.',
  },
]

const features = [
  {
    title: 'Checklist enforcement modes',
    body: 'Switch between strict and flexible readiness modes per account or environment.',
  },
  {
    title: 'Trade + missed-trade system',
    body: 'Log taken trades and quality setups you skipped to measure hesitation and intent drift.',
  },
  {
    title: 'Revision-safe history',
    body: 'Edits keep revision metadata so reports remain auditable when sessions are reviewed later.',
  },
  {
    title: 'Behavior analytics',
    body: 'Track process metrics by setup, session, and instrument, not only by outcome.',
  },
]

const method = [
  {
    n: '01',
    title: 'Prepare',
    body: 'Set context for the day with checklist, account limits, and scenario rules.',
  },
  {
    n: '02',
    title: 'Execute',
    body: 'Log entries and exits with market context, emotion, and setup metadata.',
  },
  {
    n: '03',
    title: 'Inspect',
    body: 'Score execution quality and identify where discipline slipped under pressure.',
  },
  {
    n: '04',
    title: 'Refine',
    body: 'Push review outcomes into updated rules for the next trading session.',
  },
]

const faqs = [
  {
    q: 'Is broker integration required?',
    a: 'No. Manual logging is supported by default and keeps review intentional with richer context.',
  },
  {
    q: 'Can I manage multiple accounts?',
    a: 'Yes. You can work with account-specific constraints and review performance by account.',
  },
  {
    q: 'Does this work for futures, forex, crypto, and equities?',
    a: 'Yes. The workflow is instrument-agnostic and designed for mixed market participation.',
  },
  {
    q: 'What is tracked beyond P/L?',
    a: 'Rule adherence, checklist status, setup quality, missed opportunities, and execution behavior.',
  },
]

const metrics = [
  { k: 'Weekly Review Rate', v: '92%' },
  { k: 'Rule Break Alerts', v: 'Live' },
  { k: 'Session Fields', v: 'Structured' },
]

function onScroll() {
  scrolled.value = window.scrollY > 12
}

onMounted(() => {
  onScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll)
})
</script>

<template>
  <div class="lp">
    <header class="nav" :class="{ scrolled }">
      <div class="container nav-inner">
        <a href="#" class="brand" aria-label="IZLedger home">
          <span class="mark">IZ</span>
          <span class="wordmark">IZLedger</span>
        </a>
        <nav class="nav-links" aria-label="Main">
          <a v-for="item in nav" :key="item.label" :href="item.href">{{ item.label }}</a>
        </nav>
        <div class="nav-cta">
          <a class="btn ghost desktop-only" href="/login">Log in</a>
          <a class="btn solid" href="/register">Start Free</a>
        </div>
      </div>
    </header>

    <main>
      <section class="hero">
        <div class="bg-grid" />
        <div class="blob blob-a" />
        <div class="blob blob-b" />

        <div class="container hero-layout">
          <div class="hero-copy anim-up">
            <p class="kicker">Trade Process OS</p>
            <h1>Redesigned for disciplined execution, not diary-style journaling.</h1>
            <p class="sub">
              Build a daily operating loop around checklist gating, structured logging, and behavior review.
              IZLedger turns sessions into decisions you can debug.
            </p>
            <div class="actions">
              <a class="btn solid big" href="/register">Create Account</a>
              <a class="btn ghost big" href="/login">Open App</a>
            </div>
          </div>

          <article class="hero-panel anim-up delay-1" aria-label="Session command panel">
            <div class="panel-top">
              <h2>Session Command Panel</h2>
              <span>New York open</span>
            </div>

            <div class="checkline">
              <div class="checkline-head">Readiness Gate</div>
              <ul>
                <li><i class="ok" /> Bias mapped</li>
                <li><i class="ok" /> Max loss cap set</li>
                <li><i class="ok" /> Setup criteria aligned</li>
                <li><i class="warn" /> News filter pending</li>
              </ul>
            </div>

            <div class="snap-grid">
              <div>
                <small>Trades Today</small>
                <strong>5</strong>
              </div>
              <div>
                <small>Rule Breaks</small>
                <strong class="t-warn">1</strong>
              </div>
              <div>
                <small>Process Score</small>
                <strong>81 / 100</strong>
              </div>
              <div>
                <small>Net Session</small>
                <strong class="t-good">+$320</strong>
              </div>
            </div>
          </article>
        </div>

        <div class="container metric-row anim-up delay-2">
          <div v-for="m in metrics" :key="m.k" class="metric">
            <p>{{ m.k }}</p>
            <h3>{{ m.v }}</h3>
          </div>
        </div>
      </section>

      <section class="tape" aria-label="Highlights">
        <div class="tape-track">
          <span v-for="(t, idx) in tape" :key="`${t}-${idx}`"><i />{{ t }}</span>
        </div>
      </section>

      <section class="section" id="platform">
        <div class="container">
          <div class="section-head anim-up">
            <p class="kicker">Platform</p>
            <h2>A new architecture for how you review trading decisions</h2>
            <p>
              This redesign is centered on operational clarity. Every module helps you enforce intent, capture behavior,
              and sharpen tomorrow's plan.
            </p>
          </div>

          <div class="tri-grid">
            <article v-for="c in cards" :key="c.title" class="card anim-up">
              <h3>{{ c.title }}</h3>
              <p>{{ c.text }}</p>
            </article>
          </div>
        </div>
      </section>

      <section class="section alt">
        <div class="container">
          <div class="feature-grid">
            <article v-for="f in features" :key="f.title" class="feature-card anim-up">
              <h3>{{ f.title }}</h3>
              <p>{{ f.body }}</p>
            </article>
          </div>
        </div>
      </section>

      <section class="section" id="method">
        <div class="container">
          <div class="section-head anim-up">
            <p class="kicker">Method</p>
            <h2>One session loop. Four clear states.</h2>
          </div>

          <div class="method-grid">
            <article v-for="m in method" :key="m.n" class="method-card anim-up">
              <span>{{ m.n }}</span>
              <h3>{{ m.title }}</h3>
              <p>{{ m.body }}</p>
            </article>
          </div>
        </div>
      </section>

      <section class="section alt" id="faq">
        <div class="container faq-layout">
          <div class="section-head left anim-up">
            <p class="kicker">FAQ</p>
            <h2>Common questions</h2>
          </div>
          <div class="faq-list anim-up">
            <details v-for="f in faqs" :key="f.q" class="faq-item">
              <summary>
                <span>{{ f.q }}</span>
                <b>+</b>
              </summary>
              <p>{{ f.a }}</p>
            </details>
          </div>
        </div>
      </section>

      <section class="cta">
        <div class="container cta-wrap anim-up">
          <p class="kicker">Ready</p>
          <h2>Use a review system that raises your execution floor.</h2>
          <p>Build your account, set your gates, and start journaling with structure.</p>
          <div class="actions center">
            <a class="btn solid big" href="/register">Get Started</a>
            <a class="btn ghost big" href="/login">Log in</a>
          </div>
        </div>
      </section>
    </main>

    <footer class="footer">
      <div class="container foot-inner">
        <div>
          <strong>IZLedger</strong>
          <p>Process over impulse.</p>
        </div>
        <span>All rights reserved</span>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.lp {
  --lp-bg: var(--bg);
  --lp-bg-soft: var(--bg-soft);
  --lp-surface: var(--panel);
  --lp-surface-2: var(--panel-strong);
  --lp-ink: var(--text);
  --lp-muted: var(--muted);
  --lp-line: color-mix(in srgb, var(--border) 86%, transparent 14%);
  --lp-accent: var(--primary);
  --lp-accent-soft: color-mix(in srgb, var(--primary) 16%, transparent 84%);
  --lp-highlight: var(--warning);
  --lp-highlight-soft: color-mix(in srgb, var(--warning) 20%, transparent 80%);
  --lp-ok: var(--primary);
  --lp-shadow: var(--shadow-soft);
  --lp-accent-deep: color-mix(in srgb, var(--primary) 78%, var(--text) 22%);
  color: var(--lp-ink);
  background:
    radial-gradient(circle at 0% 0%, color-mix(in srgb, var(--lp-accent) 22%, transparent), transparent 34%),
    radial-gradient(circle at 100% 12%, color-mix(in srgb, var(--lp-highlight) 22%, transparent), transparent 32%),
    var(--lp-bg);
}

:global(html) {
  scroll-behavior: smooth;
}

:global(body) {
  margin: 0;
}

.container {
  width: min(1160px, calc(100% - 2.2rem));
  margin: 0 auto;
}

.nav {
  position: fixed;
  inset: 0 0 auto;
  z-index: 40;
  border-bottom: 1px solid transparent;
  transition: background 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.nav.scrolled {
  border-color: var(--lp-line);
  background: color-mix(in srgb, var(--lp-surface-2) 84%, transparent);
  backdrop-filter: blur(10px);
  box-shadow: var(--lp-shadow);
}

.nav-inner {
  min-height: 68px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 0.72rem;
  text-decoration: none;
}

.mark {
  width: 2rem;
  height: 2rem;
  border-radius: 0.58rem;
  background: linear-gradient(135deg, var(--lp-accent), var(--lp-accent-deep));
  color: var(--lp-surface-2);
  display: grid;
  place-items: center;
  font-size: 0.72rem;
  font-family: var(--font-display);
  font-weight: 700;
  letter-spacing: 0.08em;
}

.wordmark {
  color: var(--lp-ink);
  font-size: 1rem;
  font-family: var(--font-display);
  font-weight: 700;
  letter-spacing: -0.02em;
}

.nav-links {
  display: inline-flex;
  align-items: center;
  gap: 1.4rem;
}

.nav-links a {
  text-decoration: none;
  color: var(--lp-muted);
  font-size: 0.9rem;
  font-weight: 600;
  font-family: var(--font-body);
}

.nav-links a:hover {
  color: var(--lp-ink);
}

.nav-cta {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
}

.btn {
  border-radius: 0.72rem;
  text-decoration: none;
  font-family: var(--font-body);
  font-size: 0.87rem;
  font-weight: 700;
  padding: 0.62rem 1rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: transform 160ms ease, box-shadow 160ms ease, background 160ms ease, border-color 160ms ease;
}

.btn:hover {
  transform: translateY(-1px);
}

.btn.solid {
  color: var(--lp-bg);
  background: linear-gradient(135deg, var(--lp-accent), var(--lp-accent-deep));
  box-shadow: 0 10px 20px color-mix(in srgb, var(--lp-accent) 28%, transparent);
}

.btn.ghost {
  color: var(--lp-ink);
  border: 1px solid var(--lp-line);
  background: color-mix(in srgb, var(--lp-surface-2) 70%, transparent);
}

.btn.big {
  padding: 0.8rem 1.2rem;
  font-size: 0.92rem;
}

.hero {
  position: relative;
  overflow: hidden;
  padding: 7.8rem 0 2.4rem;
}

.bg-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(to right, color-mix(in srgb, var(--lp-line) 42%, transparent) 1px, transparent 1px),
    linear-gradient(to bottom, color-mix(in srgb, var(--lp-line) 42%, transparent) 1px, transparent 1px);
  background-size: 44px 44px;
  mask-image: radial-gradient(circle at 50% 18%, black, transparent 72%);
  pointer-events: none;
}

.blob {
  position: absolute;
  border-radius: 999px;
  pointer-events: none;
}

.blob-a {
  width: 360px;
  height: 360px;
  top: -120px;
  left: -120px;
  background: radial-gradient(circle, color-mix(in srgb, var(--lp-accent) 24%, transparent), transparent 72%);
}

.blob-b {
  width: 360px;
  height: 360px;
  top: -80px;
  right: -130px;
  background: radial-gradient(circle, color-mix(in srgb, var(--lp-highlight) 24%, transparent), transparent 72%);
}

.hero-layout {
  display: grid;
  grid-template-columns: 1.08fr 0.92fr;
  gap: 1rem;
  align-items: center;
}

.kicker {
  margin: 0;
  color: color-mix(in srgb, var(--lp-accent) 76%, var(--lp-ink) 24%);
  font-size: 0.74rem;
  font-family: var(--font-body);
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.16em;
}

.hero-copy h1 {
  margin: 0.55rem 0 0;
  font-family: var(--font-display);
  font-size: clamp(2rem, 5.7vw, 4rem);
  line-height: 1.05;
  letter-spacing: -0.03em;
}

.sub {
  margin: 1rem 0 0;
  color: var(--lp-muted);
  line-height: 1.68;
  max-width: 48ch;
  font-family: var(--font-body);
}

.actions {
  margin-top: 1.5rem;
  display: flex;
  gap: 0.65rem;
  flex-wrap: wrap;
}

.actions.center {
  justify-content: center;
}

.hero-panel {
  border: 1px solid var(--lp-line);
  border-radius: 1rem;
  background: linear-gradient(180deg, var(--lp-surface), var(--lp-surface-2));
  padding: 1rem;
  box-shadow: var(--lp-shadow);
}

.panel-top {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.8rem;
}

.panel-top h2 {
  margin: 0;
  font-size: 1.04rem;
  font-family: var(--font-display);
}

.panel-top span {
  color: var(--lp-muted);
  font-size: 0.76rem;
  font-weight: 600;
  font-family: var(--font-body);
}

.checkline {
  margin-top: 0.9rem;
  border: 1px solid var(--lp-line);
  border-radius: 0.82rem;
  padding: 0.75rem;
  background: var(--lp-surface-2);
}

.checkline-head {
  color: var(--lp-muted);
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  font-weight: 800;
  font-family: var(--font-body);
}

.checkline ul {
  margin: 0.55rem 0 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.44rem;
}

.checkline li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  font-family: var(--font-body);
}

.checkline i {
  width: 0.58rem;
  height: 0.58rem;
  border-radius: 999px;
  display: inline-block;
  flex: 0 0 auto;
}

.checkline i.ok {
  background: var(--lp-ok);
}

.checkline i.warn {
  background: var(--lp-highlight);
}

.snap-grid {
  margin-top: 0.9rem;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.6rem;
}

.snap-grid > div {
  border: 1px solid var(--lp-line);
  border-radius: 0.7rem;
  background: var(--lp-surface-2);
  padding: 0.66rem;
}

.snap-grid small {
  color: var(--lp-muted);
  font-size: 0.74rem;
  display: block;
  margin-bottom: 0.2rem;
  font-family: var(--font-body);
}

.snap-grid strong {
  font-size: 1rem;
  font-family: var(--font-display);
}

.t-good {
  color: var(--lp-ok);
}

.t-warn {
  color: var(--lp-highlight);
}

.metric-row {
  margin-top: 1.1rem;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.6rem;
}

.metric {
  border: 1px solid var(--lp-line);
  border-radius: 0.8rem;
  background: var(--lp-surface-2);
  padding: 0.72rem 0.8rem;
}

.metric p {
  margin: 0;
  color: var(--lp-muted);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 800;
  font-family: var(--font-body);
}

.metric h3 {
  margin: 0.26rem 0 0;
  font-size: 1.14rem;
  font-family: var(--font-display);
}

.tape {
  border-top: 1px solid var(--lp-line);
  border-bottom: 1px solid var(--lp-line);
  background: color-mix(in srgb, var(--lp-surface) 92%, var(--lp-surface-2));
  padding: 0.76rem 0;
  overflow: hidden;
}

.tape-track {
  width: max-content;
  display: flex;
  align-items: center;
  gap: 1.65rem;
  animation: tape-run 24s linear infinite;
}

.tape-track span {
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 0.48rem;
  color: var(--lp-muted);
  font-size: 0.83rem;
  font-weight: 600;
  font-family: var(--font-body);
}

.tape-track i {
  width: 0.35rem;
  height: 0.35rem;
  border-radius: 999px;
  background: var(--lp-accent);
  display: inline-block;
}

.section {
  padding: 5.2rem 0;
}

.section.alt {
  background: var(--lp-bg-soft);
  border-top: 1px solid var(--lp-line);
  border-bottom: 1px solid var(--lp-line);
}

.section-head {
  text-align: center;
  max-width: 760px;
  margin: 0 auto 2rem;
}

.section-head.left {
  text-align: left;
  margin: 0;
}

.section-head h2 {
  margin: 0.55rem 0 0;
  font-family: var(--font-display);
  font-size: clamp(1.7rem, 3.4vw, 2.6rem);
  letter-spacing: -0.02em;
}

.section-head p {
  margin: 0.85rem 0 0;
  color: var(--lp-muted);
  line-height: 1.66;
  font-family: var(--font-body);
}

.tri-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
}

.card {
  border: 1px solid var(--lp-line);
  border-radius: 0.95rem;
  background: var(--lp-surface-2);
  padding: 1rem;
}

.card h3 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.08rem;
}

.card p {
  margin: 0.68rem 0 0;
  color: var(--lp-muted);
  line-height: 1.62;
  font-family: var(--font-body);
}

.feature-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
}

.feature-card {
  border: 1px solid var(--lp-line);
  border-radius: 0.95rem;
  background: var(--lp-surface-2);
  padding: 1rem;
  box-shadow: inset 0 3px 0 var(--lp-accent);
}

.feature-card h3 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.03rem;
}

.feature-card p {
  margin: 0.62rem 0 0;
  color: var(--lp-muted);
  font-family: var(--font-body);
  line-height: 1.62;
}

.method-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.62rem;
}

.method-card {
  border: 1px solid var(--lp-line);
  border-radius: 0.9rem;
  background: var(--lp-surface-2);
  padding: 0.9rem;
}

.method-card span {
  font-size: 0.73rem;
  color: var(--lp-muted);
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-weight: 800;
  font-family: var(--font-body);
}

.method-card h3 {
  margin: 0.44rem 0 0;
  font-family: var(--font-display);
  font-size: 1rem;
}

.method-card p {
  margin: 0.58rem 0 0;
  color: var(--lp-muted);
  line-height: 1.6;
  font-family: var(--font-body);
}

.faq-layout {
  display: grid;
  grid-template-columns: 0.8fr 1.2fr;
  gap: 0.8rem;
}

.faq-list {
  border: 1px solid var(--lp-line);
  border-radius: 0.95rem;
  background: var(--lp-surface-2);
  overflow: hidden;
}

.faq-item {
  border-bottom: 1px solid var(--lp-line);
  padding: 0 0.9rem;
}

.faq-item:last-child {
  border-bottom: 0;
}

.faq-item summary {
  list-style: none;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  cursor: pointer;
  padding: 0.92rem 0;
  font-family: var(--font-body);
  font-weight: 700;
}

.faq-item summary::-webkit-details-marker {
  display: none;
}

.faq-item b {
  color: var(--lp-muted);
}

.faq-item p {
  margin: 0 0 0.9rem;
  color: var(--lp-muted);
  line-height: 1.6;
  font-family: var(--font-body);
}

.cta {
  padding: 5.4rem 0 5rem;
  border-top: 1px solid var(--lp-line);
  background:
    radial-gradient(circle at 50% 0, color-mix(in srgb, var(--lp-accent) 20%, transparent), transparent 60%),
    var(--lp-surface);
}

.cta-wrap {
  text-align: center;
  border: 1px solid var(--lp-line);
  border-radius: 1rem;
  background: var(--lp-surface-2);
  max-width: 840px;
  padding: 2rem;
  box-shadow: var(--lp-shadow);
}

.cta-wrap h2 {
  margin: 0.55rem 0 0;
  font-family: var(--font-display);
  font-size: clamp(1.75rem, 4.8vw, 2.8rem);
  letter-spacing: -0.02em;
}

.cta-wrap p {
  margin: 0.9rem auto 0;
  max-width: 56ch;
  color: var(--lp-muted);
  line-height: 1.66;
  font-family: var(--font-body);
}

.footer {
  border-top: 1px solid var(--lp-line);
  background: var(--lp-surface-2);
  padding: 1.2rem 0;
}

.foot-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.8rem;
  flex-wrap: wrap;
}

.foot-inner strong {
  display: block;
  font-family: var(--font-display);
  font-size: 0.94rem;
}

.foot-inner p,
.foot-inner span {
  margin: 0.18rem 0 0;
  color: var(--lp-muted);
  font-family: var(--font-body);
  font-size: 0.83rem;
}

.anim-up {
  animation: rise 620ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

.delay-1 {
  animation-delay: 90ms;
}

.delay-2 {
  animation-delay: 180ms;
}

@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(22px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes tape-run {
  from {
    transform: translateX(0);
  }

  to {
    transform: translateX(-50%);
  }
}

@media (max-width: 1060px) {
  .hero-layout,
  .tri-grid,
  .feature-grid,
  .method-grid,
  .faq-layout,
  .metric-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .method-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 760px) {
  .container {
    width: min(1160px, calc(100% - 1.2rem));
  }

  .desktop-only,
  .nav-links {
    display: none;
  }

  .hero {
    padding-top: 6.4rem;
  }

  .hero-layout,
  .tri-grid,
  .feature-grid,
  .method-grid,
  .faq-layout,
  .metric-row {
    grid-template-columns: 1fr;
  }

  .section {
    padding: 4.2rem 0;
  }

  .cta-wrap {
    padding: 1.24rem;
  }
}
</style>
