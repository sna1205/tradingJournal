<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { CheckCircle2, Flag, LineChart, ShieldCheck, Sparkles } from 'lucide-vue-next'

const scrolled = ref(false)
let revealObserver: IntersectionObserver | null = null

const nav = [
  { label: 'Platform', href: '#platform' },
  { label: 'Method', href: '#method' },
  { label: 'FAQ', href: '#faq' },
]

const readiness = [
  { label: 'Bias defined', state: 'ok' },
  { label: 'Risk cap set', state: 'ok' },
  { label: 'Setup criteria aligned', state: 'ok' },
  { label: 'News filter pending', state: 'warn' },
]

const heroStats = [
  { k: 'Trades Logged', v: '5', tone: '' },
  { k: 'Rule Breaks', v: '1', tone: 't-warn' },
  { k: 'Execution Score', v: '81 / 100', tone: '' },
  { k: 'Session P/L', v: '+$320', tone: 't-good' },
]

const problemPoints = [
  'Traders record entries but never review behavior.',
  'Most journals track profit and ignore execution quality.',
  'Without structure, discipline does not improve consistently.',
  'Mistakes repeat when decisions are not analyzed.',
]

const productModules = [
  {
    preview: 'Trade Logging Interface',
    title: 'Log trades with context, not just price.',
    body: 'Capture setup, direction, risk, execution notes, and emotional state while context is still fresh.',
    actions: ['Record setup and execution fields', 'Tag session context', 'Attach post-trade notes'],
  },
  {
    preview: 'Behavior Analytics Dashboard',
    title: 'Review behavior patterns across sessions.',
    body: 'Surface discipline trends by setup, session, and account so your best process becomes repeatable.',
    actions: ['Track rule adherence trends', 'Compare execution quality by setup', 'Spot recurring mistakes quickly'],
  },
  {
    preview: 'Trade Review Screen',
    title: 'Score execution and close the feedback loop.',
    body: 'Review each trade with structured criteria, mark rule breaks, and convert lessons into next-session rules.',
    actions: ['Score process quality trade by trade', 'Flag rule breaks and missed setups', 'Refine your plan with evidence'],
  },
]

const features = [
  {
    icon: LineChart,
    title: 'Behavior Analytics',
    body: 'Understand patterns behind winning and losing trades.',
  },
  {
    icon: ShieldCheck,
    title: 'Execution Review',
    body: 'Score each trade based on rule adherence and process quality.',
  },
  {
    icon: CheckCircle2,
    title: 'Structured Journaling',
    body: 'Capture setups, entries, exits, and trading context with consistency.',
  },
  {
    icon: Flag,
    title: 'Missed Trade Tracking',
    body: "Log high-quality setups you did not take and reduce hesitation over time.",
  },
]

const audience = [
  {
    title: 'Day Traders',
    body: 'Track intraday decisions and improve execution one session at a time.',
  },
  {
    title: 'Prop Firm Traders',
    body: 'Stay disciplined, respect constraints, and review rule adherence under pressure.',
  },
  {
    title: 'Developing Traders',
    body: 'Build consistent habits with a structured process from planning to review.',
  },
]

const method = [
  {
    n: '01',
    title: 'Prepare',
    body: 'Define bias, risk limits, and your trading plan before execution.',
  },
  {
    n: '02',
    title: 'Execute',
    body: 'Log trades and capture decision context while the market is live.',
  },
  {
    n: '03',
    title: 'Review',
    body: 'Analyze behavior, execution quality, and rule adherence after session close.',
  },
  {
    n: '04',
    title: 'Improve',
    body: 'Identify mistakes and refine process rules for the next trading day.',
  },
]

const socialProof = [
  'Built with feedback from active day traders and prop firm participants.',
  'Private beta users are reviewing sessions daily with structured scorecards.',
]

const faqs = [
  {
    q: 'Is broker integration required to use IZLedger?',
    a: 'No. Manual trade logging is supported by default so your review process stays intentional and detailed.',
  },
  {
    q: 'Can I use IZLedger across multiple accounts?',
    a: 'Yes. You can manage account-specific constraints and compare execution quality by account.',
  },
  {
    q: 'Does this work for futures, forex, crypto, and equities?',
    a: 'Yes. IZLedger is instrument-agnostic and designed for mixed-market workflows.',
  },
  {
    q: 'Is there a free plan?',
    a: 'Yes. Start on Free, then upgrade to Pro when you need deeper behavior analytics and advanced review tooling.',
  },
]

function onScroll() {
  scrolled.value = window.scrollY > 10
}

function setupRevealEffects() {
  const nodes = Array.from(document.querySelectorAll<HTMLElement>('[data-reveal]'))
  if (!nodes.length) return

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    nodes.forEach((node) => node.classList.add('is-visible'))
    return
  }

  revealObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return
        entry.target.classList.add('is-visible')
        revealObserver?.unobserve(entry.target)
      })
    },
    {
      threshold: 0.18,
      rootMargin: '0px 0px -8% 0px',
    },
  )

  nodes.forEach((node) => revealObserver?.observe(node))
}

onMounted(() => {
  onScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
  setupRevealEffects()
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll)
  revealObserver?.disconnect()
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
      <section class="hero" id="top">
        <div class="bg-grid" />
        <div class="bg-chart" />
        <div class="blob blob-a" />
        <div class="blob blob-b" />

        <div class="container hero-layout">
          <div class="hero-copy" data-reveal>
            <p class="cred-line">Designed for day traders and prop firm traders.</p>
            <h1>A trading journal built for disciplined execution.</h1>
            <p class="sub">
              Track trades, review behavior, and improve decision making through a structured loop for planning,
              execution, and post-session analysis.
            </p>

            <div class="actions">
              <a class="btn solid big glow" href="/register">Start Free</a>
              <a class="btn ghost big" href="#inside">View Platform</a>
            </div>

            <p class="pricing-line">Free + Pro pricing. Start free and upgrade when your review process scales.</p>
          </div>

          <article class="hero-panel" data-reveal aria-label="Session command panel">
            <div class="panel-top">
              <h2>Session Command Panel</h2>
              <span>New York Open</span>
            </div>

            <div class="checkline">
              <div class="checkline-head">Readiness Gate</div>
              <ul>
                <li
                  v-for="(item, idx) in readiness"
                  :key="item.label"
                  :style="{ '--tick-delay': `${120 + idx * 120}ms` }"
                >
                  <i :class="item.state" />
                  <span>{{ item.label }}</span>
                </li>
              </ul>
            </div>

            <div class="snap-grid">
              <div v-for="stat in heroStats" :key="stat.k" class="stat-card">
                <small>{{ stat.k }}</small>
                <strong :class="stat.tone">{{ stat.v }}</strong>
              </div>
            </div>
          </article>
        </div>
      </section>

      <section class="section problem alt" id="problem">
        <div class="container problem-layout">
          <div class="section-head left" data-reveal>
            <p class="kicker">Problem</p>
            <h2>Most trading journals only track P/L.</h2>
            <p>
              Logging entries and exits is not enough. Traders improve when behavior and execution quality are reviewed
              with structure, not memory.
            </p>
          </div>

          <article class="problem-card lift-card" data-reveal>
            <ul>
              <li v-for="point in problemPoints" :key="point">
                <span class="dot" />
                <span>{{ point }}</span>
              </li>
            </ul>
            <p class="solution">
              IZLedger turns trade history into a decision-improvement system with session tracking, rule break
              detection, and execution scoring.
            </p>
          </article>
        </div>
      </section>

      <section class="section" id="inside">
        <div class="container">
          <div class="section-head" data-reveal>
            <p class="kicker">Inside IZLedger</p>
            <h2>What traders actually do inside the platform</h2>
            <p>Log trades, review execution, identify rule breaks, and improve decision quality with every session.</p>
          </div>

          <div class="inside-grid">
            <article
              v-for="(module, idx) in productModules"
              :key="module.title"
              class="inside-card lift-card"
              data-reveal
              :style="{ '--reveal-delay': `${idx * 80}ms` }"
            >
              <div class="mock-shell" aria-hidden="true">
                <div class="mock-head">
                  <span />
                  <span />
                  <span />
                </div>
                <div class="mock-body">
                  <div class="mock-line wide" />
                  <div class="mock-line" />
                  <div class="mock-line short" />
                </div>
              </div>
              <p class="card-tag">{{ module.preview }}</p>
              <h3>{{ module.title }}</h3>
              <p>{{ module.body }}</p>
              <ul>
                <li v-for="item in module.actions" :key="item">{{ item }}</li>
              </ul>
            </article>
          </div>
        </div>
      </section>

      <section class="section alt" id="platform">
        <div class="container">
          <div class="section-head" data-reveal>
            <p class="kicker">Platform Features</p>
            <h2>Built for behavior-first performance review</h2>
          </div>

          <div class="feature-grid">
            <article
              v-for="(feature, idx) in features"
              :key="feature.title"
              class="feature-card lift-card"
              data-reveal
              :style="{ '--reveal-delay': `${idx * 80}ms` }"
            >
              <span class="feature-icon">
                <component :is="feature.icon" />
              </span>
              <h3>{{ feature.title }}</h3>
              <p>{{ feature.body }}</p>
            </article>
          </div>
        </div>
      </section>

      <section class="section" id="audience">
        <div class="container">
          <div class="section-head" data-reveal>
            <p class="kicker">Who It Is For</p>
            <h2>Built for serious retail traders</h2>
          </div>

          <div class="audience-grid">
            <article
              v-for="(item, idx) in audience"
              :key="item.title"
              class="audience-card lift-card"
              data-reveal
              :style="{ '--reveal-delay': `${idx * 80}ms` }"
            >
              <h3>{{ item.title }}</h3>
              <p>{{ item.body }}</p>
            </article>
          </div>
        </div>
      </section>

      <section class="section alt" id="method">
        <div class="container">
          <div class="section-head" data-reveal>
            <p class="kicker">Method</p>
            <h2>The trading improvement loop</h2>
          </div>

          <div class="method-grid">
            <article
              v-for="(step, idx) in method"
              :key="step.n"
              class="method-card lift-card"
              data-reveal
              :style="{ '--reveal-delay': `${idx * 80}ms` }"
            >
              <span>{{ step.n }}</span>
              <h3>{{ step.title }}</h3>
              <p>{{ step.body }}</p>
            </article>
          </div>
        </div>
      </section>

      <section class="section proof" id="social">
        <div class="container proof-wrap" data-reveal>
          <p class="kicker">Early Credibility</p>
          <h2>Built with active trader feedback</h2>
          <ul>
            <li v-for="line in socialProof" :key="line">
              <Sparkles class="proof-icon" />
              <span>{{ line }}</span>
            </li>
          </ul>
        </div>
      </section>

      <section class="section alt" id="faq">
        <div class="container faq-layout">
          <div class="section-head left" data-reveal>
            <p class="kicker">FAQ</p>
            <h2>Common questions</h2>
          </div>

          <div class="faq-list" data-reveal>
            <details v-for="item in faqs" :key="item.q" class="faq-item">
              <summary>
                <span>{{ item.q }}</span>
                <b>+</b>
              </summary>
              <p>{{ item.a }}</p>
            </details>
          </div>
        </div>
      </section>

      <section class="cta" id="final">
        <div class="container cta-wrap" data-reveal>
          <p class="kicker">Final Call</p>
          <h2>Raise your execution standard.</h2>
          <p>Start journaling with structure and turn your trades into data you can actually learn from.</p>

          <div class="actions center">
            <a class="btn solid big glow" href="/register">Start Free</a>
            <a class="btn ghost big" href="/login">Log in</a>
          </div>
        </div>
      </section>
    </main>

    <footer class="footer">
      <div class="container foot-inner">
        <div>
          <strong>IZLedger</strong>
          <p>Discipline first. Data-backed execution.</p>
        </div>
        <span>All rights reserved</span>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.lp {
  --lp-bg: #050d0a;
  --lp-bg-soft: #07130f;
  --lp-surface: #0b1914;
  --lp-surface-2: #10221b;
  --lp-ink: #ecf5ef;
  --lp-muted: #9ab0a5;
  --lp-line: rgba(89, 132, 111, 0.36);
  --lp-accent: #44c78a;
  --lp-accent-deep: #2d8e63;
  --lp-highlight: #d8b466;
  --lp-shadow: 0 20px 42px rgba(1, 6, 4, 0.44);
  color: var(--lp-ink);
  background:
    radial-gradient(circle at 8% -2%, rgba(66, 184, 124, 0.2), transparent 34%),
    radial-gradient(circle at 94% 14%, rgba(31, 76, 56, 0.5), transparent 33%),
    linear-gradient(180deg, #040b08 0%, #030806 100%);
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
  background: rgba(9, 19, 15, 0.82);
  backdrop-filter: blur(12px);
  box-shadow: 0 14px 34px rgba(0, 0, 0, 0.3);
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
  color: #042414;
  display: grid;
  place-items: center;
  font-size: 0.72rem;
  font-family: var(--font-display);
  font-weight: 800;
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
  font-size: 0.89rem;
  font-weight: 600;
  font-family: var(--font-body);
  transition: color 180ms ease;
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
  border-radius: 0.74rem;
  text-decoration: none;
  font-family: var(--font-body);
  font-size: 0.87rem;
  font-weight: 700;
  padding: 0.62rem 1rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid transparent;
  transition: transform 180ms ease, box-shadow 180ms ease, background 180ms ease, border-color 180ms ease;
}

.btn:hover {
  transform: translateY(-1px);
}

.btn.solid {
  color: #042414;
  background: linear-gradient(135deg, var(--lp-accent), var(--lp-accent-deep));
  box-shadow: 0 10px 20px rgba(34, 120, 80, 0.45);
}

.btn.solid.glow {
  box-shadow: 0 12px 24px rgba(50, 193, 125, 0.3), 0 0 0 1px rgba(95, 221, 160, 0.22) inset;
}

.btn.solid.glow:hover {
  box-shadow: 0 16px 30px rgba(44, 188, 119, 0.38), 0 0 30px rgba(72, 227, 149, 0.24);
}

.btn.ghost {
  color: var(--lp-ink);
  border-color: var(--lp-line);
  background: rgba(15, 33, 26, 0.72);
}

.btn.ghost:hover {
  border-color: rgba(106, 174, 141, 0.62);
  background: rgba(20, 41, 33, 0.92);
}

.btn.big {
  padding: 0.8rem 1.24rem;
  font-size: 0.92rem;
}

.hero {
  position: relative;
  overflow: hidden;
  padding: 7.8rem 0 3.4rem;
}

.bg-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(to right, rgba(89, 132, 111, 0.24) 1px, transparent 1px),
    linear-gradient(to bottom, rgba(89, 132, 111, 0.24) 1px, transparent 1px);
  background-size: 44px 44px;
  mask-image: radial-gradient(circle at 50% 18%, black, transparent 72%);
  pointer-events: none;
}

.bg-chart {
  position: absolute;
  inset: auto 0 16% 0;
  height: 180px;
  background:
    linear-gradient(120deg, transparent 0%, rgba(66, 199, 136, 0.18) 38%, transparent 78%),
    linear-gradient(160deg, transparent 12%, rgba(216, 180, 102, 0.14) 58%, transparent 85%);
  mask-image: linear-gradient(180deg, transparent, black 28%, black 70%, transparent);
  pointer-events: none;
}

.blob {
  position: absolute;
  border-radius: 999px;
  pointer-events: none;
}

.blob-a {
  width: 380px;
  height: 380px;
  top: -140px;
  left: -140px;
  background: radial-gradient(circle, rgba(68, 199, 138, 0.24), transparent 72%);
}

.blob-b {
  width: 380px;
  height: 380px;
  top: -90px;
  right: -130px;
  background: radial-gradient(circle, rgba(216, 180, 102, 0.2), transparent 70%);
}

.hero-layout {
  display: grid;
  grid-template-columns: 1.06fr 0.94fr;
  gap: 1rem;
  align-items: center;
}

.cred-line {
  margin: 0;
  color: #b7d8c6;
  font-size: 0.8rem;
  font-family: var(--font-body);
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.hero-copy h1 {
  margin: 0.68rem 0 0;
  font-family: var(--font-display);
  font-size: clamp(2.08rem, 5.4vw, 3.76rem);
  line-height: 1.05;
  letter-spacing: -0.03em;
}

.sub {
  margin: 0.95rem 0 0;
  color: var(--lp-muted);
  line-height: 1.68;
  max-width: 52ch;
  font-family: var(--font-body);
}

.actions {
  margin-top: 1.42rem;
  display: flex;
  gap: 0.65rem;
  flex-wrap: wrap;
}

.actions.center {
  justify-content: center;
}

.pricing-line {
  margin: 0.82rem 0 0;
  font-size: 0.83rem;
  color: #a5c2b3;
  font-family: var(--font-body);
}

.hero-panel {
  border: 1px solid var(--lp-line);
  border-radius: 1rem;
  background:
    radial-gradient(circle at 0% 0%, rgba(68, 199, 138, 0.14), transparent 42%),
    linear-gradient(180deg, #0d1e18, #0b1814);
  padding: 1.08rem;
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
  font-size: 1.03rem;
  font-family: var(--font-display);
}

.panel-top span {
  color: var(--lp-muted);
  font-size: 0.76rem;
  font-weight: 700;
  font-family: var(--font-body);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.checkline {
  margin-top: 0.9rem;
  border: 1px solid var(--lp-line);
  border-radius: 0.82rem;
  padding: 0.78rem;
  background: rgba(14, 32, 25, 0.88);
}

.checkline-head {
  color: #a6c0b3;
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.11em;
  font-weight: 800;
  font-family: var(--font-body);
}

.checkline ul {
  margin: 0.62rem 0 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.46rem;
}

.checkline li {
  display: flex;
  align-items: center;
  gap: 0.54rem;
  font-size: 0.89rem;
  font-family: var(--font-body);
  opacity: 0;
  transform: translateY(6px);
  animation: tick-in 520ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  animation-delay: var(--tick-delay, 0ms);
}

.checkline i {
  width: 0.58rem;
  height: 0.58rem;
  border-radius: 999px;
  display: inline-block;
  box-shadow: 0 0 0 0 rgba(0, 0, 0, 0);
}

.checkline i.ok {
  background: var(--lp-accent);
}

.checkline i.warn {
  background: var(--lp-highlight);
}

.snap-grid {
  margin-top: 0.92rem;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.62rem;
}

.stat-card {
  border: 1px solid var(--lp-line);
  border-radius: 0.74rem;
  background: rgba(14, 32, 25, 0.82);
  padding: 0.7rem;
  transition: border-color 180ms ease, transform 180ms ease;
}

.stat-card:hover {
  border-color: rgba(96, 164, 131, 0.64);
  transform: translateY(-1px);
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
  color: var(--lp-accent);
}

.t-warn {
  color: var(--lp-highlight);
}

.section {
  padding: 5.2rem 0;
}

.section.alt {
  background: rgba(7, 17, 13, 0.88);
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

.kicker {
  margin: 0;
  color: #8ad7b0;
  font-size: 0.74rem;
  font-family: var(--font-body);
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.15em;
}

.section-head h2 {
  margin: 0.55rem 0 0;
  font-family: var(--font-display);
  font-size: clamp(1.72rem, 3.4vw, 2.65rem);
  letter-spacing: -0.02em;
}

.section-head p {
  margin: 0.84rem 0 0;
  color: var(--lp-muted);
  line-height: 1.66;
  font-family: var(--font-body);
}

.problem-layout {
  display: grid;
  grid-template-columns: 0.95fr 1.05fr;
  gap: 0.8rem;
  align-items: start;
}

.problem-card {
  border: 1px solid var(--lp-line);
  border-radius: 0.98rem;
  background: var(--lp-surface-2);
  padding: 1.08rem;
}

.problem-card ul {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.62rem;
}

.problem-card li {
  display: flex;
  align-items: flex-start;
  gap: 0.58rem;
  color: var(--lp-muted);
  line-height: 1.58;
  font-family: var(--font-body);
}

.dot {
  margin-top: 0.46rem;
  width: 0.38rem;
  height: 0.38rem;
  border-radius: 999px;
  background: var(--lp-highlight);
  flex: 0 0 auto;
}

.solution {
  margin: 1rem 0 0;
  color: #bfe3cf;
  line-height: 1.62;
  font-family: var(--font-body);
}

.inside-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.7rem;
}

.inside-card {
  border: 1px solid var(--lp-line);
  border-radius: 1rem;
  background: var(--lp-surface-2);
  padding: 1rem;
}

.mock-shell {
  border: 1px solid rgba(98, 145, 121, 0.42);
  border-radius: 0.8rem;
  background: rgba(9, 23, 17, 0.84);
  overflow: hidden;
  margin-bottom: 0.85rem;
}

.mock-head {
  height: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.32rem;
  padding: 0 0.62rem;
  border-bottom: 1px solid rgba(98, 145, 121, 0.3);
}

.mock-head span {
  width: 0.34rem;
  height: 0.34rem;
  border-radius: 999px;
  background: rgba(141, 188, 164, 0.6);
}

.mock-body {
  padding: 0.62rem;
  display: grid;
  gap: 0.44rem;
}

.mock-line {
  height: 0.42rem;
  border-radius: 999px;
  background: rgba(72, 122, 98, 0.56);
}

.mock-line.wide {
  width: 100%;
}

.mock-line.short {
  width: 48%;
}

.card-tag {
  margin: 0;
  color: #a4cfb8;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  font-family: var(--font-body);
  font-weight: 700;
}

.inside-card h3 {
  margin: 0.52rem 0 0;
  font-family: var(--font-display);
  font-size: 1.08rem;
}

.inside-card p {
  margin: 0.58rem 0 0;
  color: var(--lp-muted);
  line-height: 1.58;
  font-family: var(--font-body);
}

.inside-card ul {
  margin: 0.72rem 0 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.4rem;
}

.inside-card li {
  color: #b7d4c5;
  font-size: 0.86rem;
  font-family: var(--font-body);
}

.feature-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.65rem;
}

.feature-card {
  border: 1px solid var(--lp-line);
  border-radius: 0.95rem;
  background: var(--lp-surface-2);
  padding: 1rem;
}

.feature-icon {
  width: 2rem;
  height: 2rem;
  border-radius: 0.6rem;
  display: grid;
  place-items: center;
  background: rgba(65, 178, 124, 0.16);
  border: 1px solid rgba(90, 171, 130, 0.46);
}

.feature-icon :deep(svg) {
  width: 1rem;
  height: 1rem;
  color: #87d8af;
}

.feature-card h3 {
  margin: 0.72rem 0 0;
  font-family: var(--font-display);
  font-size: 1.02rem;
}

.feature-card p {
  margin: 0.58rem 0 0;
  color: var(--lp-muted);
  font-family: var(--font-body);
  line-height: 1.6;
}

.audience-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
}

.audience-card {
  border: 1px solid var(--lp-line);
  border-radius: 0.95rem;
  background: var(--lp-surface-2);
  padding: 1rem;
}

.audience-card h3 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.04rem;
}

.audience-card p {
  margin: 0.6rem 0 0;
  color: var(--lp-muted);
  line-height: 1.62;
  font-family: var(--font-body);
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
  padding: 0.92rem;
}

.method-card span {
  font-size: 0.73rem;
  color: #a6bdb1;
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

.proof {
  border-top: 1px solid var(--lp-line);
  border-bottom: 1px solid var(--lp-line);
  background:
    radial-gradient(circle at 50% 0, rgba(68, 199, 138, 0.2), transparent 60%),
    rgba(9, 22, 16, 0.9);
}

.proof-wrap {
  max-width: 820px;
  text-align: center;
}

.proof-wrap h2 {
  margin: 0.55rem 0 0;
  font-family: var(--font-display);
  font-size: clamp(1.62rem, 3.4vw, 2.4rem);
}

.proof-wrap ul {
  margin: 1.1rem 0 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.68rem;
}

.proof-wrap li {
  border: 1px solid var(--lp-line);
  border-radius: 0.85rem;
  background: rgba(15, 34, 26, 0.82);
  padding: 0.8rem;
  color: #b8d4c5;
  font-family: var(--font-body);
  display: flex;
  align-items: center;
  gap: 0.5rem;
  justify-content: center;
}

.proof-icon {
  width: 0.95rem;
  height: 0.95rem;
  color: #85d7ae;
}

.faq-layout {
  display: grid;
  grid-template-columns: 0.82fr 1.18fr;
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
  padding: 0 0.92rem;
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
  padding: 0.94rem 0;
  font-family: var(--font-body);
  font-weight: 700;
}

.faq-item summary::-webkit-details-marker {
  display: none;
}

.faq-item b {
  color: var(--lp-muted);
  transition: transform 160ms ease;
}

.faq-item[open] b {
  transform: rotate(45deg);
}

.faq-item p {
  margin: 0 0 0.94rem;
  color: var(--lp-muted);
  line-height: 1.6;
  font-family: var(--font-body);
}

.cta {
  padding: 5.4rem 0 5rem;
  border-top: 1px solid var(--lp-line);
  background:
    radial-gradient(circle at 50% 0, rgba(68, 199, 138, 0.2), transparent 60%),
    var(--lp-surface);
}

.cta-wrap {
  text-align: center;
  border: 1px solid var(--lp-line);
  border-radius: 1rem;
  background: var(--lp-surface-2);
  max-width: 860px;
  padding: 2rem;
  box-shadow: var(--lp-shadow);
}

.cta-wrap h2 {
  margin: 0.55rem 0 0;
  font-family: var(--font-display);
  font-size: clamp(1.8rem, 4.8vw, 2.8rem);
  letter-spacing: -0.02em;
}

.cta-wrap p {
  margin: 0.9rem auto 0;
  max-width: 58ch;
  color: var(--lp-muted);
  line-height: 1.66;
  font-family: var(--font-body);
}

.footer {
  border-top: 1px solid var(--lp-line);
  background: rgba(10, 20, 16, 0.94);
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

.lift-card {
  transition: transform 200ms ease, border-color 200ms ease, box-shadow 200ms ease;
}

.lift-card:hover {
  transform: translateY(-4px);
  border-color: rgba(102, 170, 137, 0.72);
  box-shadow: 0 18px 32px rgba(0, 0, 0, 0.28);
}

[data-reveal] {
  opacity: 0;
  transform: translateY(24px);
  transition:
    opacity 620ms cubic-bezier(0.22, 1, 0.36, 1),
    transform 620ms cubic-bezier(0.22, 1, 0.36, 1);
  transition-delay: var(--reveal-delay, 0ms);
}

[data-reveal].is-visible {
  opacity: 1;
  transform: translateY(0);
}

@keyframes tick-in {
  from {
    opacity: 0;
    transform: translateY(6px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 1060px) {
  .hero-layout,
  .problem-layout,
  .inside-grid,
  .feature-grid,
  .audience-grid,
  .method-grid,
  .faq-layout {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .method-grid,
  .feature-grid {
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
  .problem-layout,
  .inside-grid,
  .feature-grid,
  .audience-grid,
  .method-grid,
  .faq-layout {
    grid-template-columns: 1fr;
  }

  .section {
    padding: 4.2rem 0;
  }

  .proof-wrap li {
    justify-content: flex-start;
    text-align: left;
  }

  .cta-wrap {
    padding: 1.3rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .btn,
  .lift-card,
  .stat-card,
  .checkline li,
  [data-reveal] {
    transition: none;
    animation: none;
  }

  [data-reveal] {
    opacity: 1;
    transform: none;
  }

  .checkline li {
    opacity: 1;
    transform: none;
  }
}
</style>
