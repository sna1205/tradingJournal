<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    value: number
    decimals?: number
    duration?: number
    prefix?: string
    suffix?: string
    sign?: boolean
    formatter?: ((value: number) => string) | null
  }>(),
  {
    decimals: 0,
    duration: 800,
    prefix: '',
    suffix: '',
    sign: false,
    formatter: null,
  }
)

const displayValue = ref(0)
let animationFrame = 0

const numberFormatter = computed(
  () =>
    new Intl.NumberFormat('en-US', {
      minimumFractionDigits: props.decimals,
      maximumFractionDigits: props.decimals,
    })
)

function normalize(value: number) {
  return Number.isFinite(value) ? value : 0
}

function stopAnimation() {
  if (animationFrame) {
    cancelAnimationFrame(animationFrame)
    animationFrame = 0
  }
}

function animateTo(targetValue: number) {
  stopAnimation()
  const target = normalize(targetValue)
  const start = displayValue.value
  const delta = target - start
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  if (Math.abs(delta) < 0.000001 || props.duration <= 0 || reducedMotion) {
    displayValue.value = target
    return
  }

  const startTime = performance.now()

  const update = (currentTime: number) => {
    const progress = Math.min((currentTime - startTime) / props.duration, 1)
    const eased = 1 - Math.pow(1 - progress, 3)
    displayValue.value = start + delta * eased

    if (progress < 1) {
      animationFrame = requestAnimationFrame(update)
    } else {
      animationFrame = 0
    }
  }

  animationFrame = requestAnimationFrame(update)
}

const text = computed(() => {
  const value = normalize(displayValue.value)

  if (props.formatter) {
    return props.formatter(value)
  }

  const core = numberFormatter.value.format(Math.abs(value))
  const rendered = `${props.prefix}${core}${props.suffix}`

  if (value < 0) return `-${rendered}`
  if (props.sign && value > 0) return `+${rendered}`
  return rendered
})

watch(
  () => props.value,
  (next) => {
    animateTo(next)
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  stopAnimation()
})
</script>

<template>
  <span class="number-display">{{ text }}</span>
</template>
