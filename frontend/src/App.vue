<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import SidebarLayout from '@/components/layout/SidebarLayout.vue'
import ToastCenter from '@/components/layout/ToastCenter.vue'
import ConfirmDialog from '@/components/layout/ConfirmDialog.vue'
import { useUiStore } from '@/stores/uiStore'
import { useAuthStore } from '@/stores/authStore'

const uiStore = useUiStore()
const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const useAuthLayout = computed(() => route.meta.layout === 'auth')

function handleUnauthorized() {
  authStore.clearSession()
  if (route.path === '/login') return
  router.replace({
    path: '/login',
    query: { redirect: route.fullPath },
  })
}

onMounted(() => {
  uiStore.initTheme()
  window.addEventListener('auth:unauthorized', handleUnauthorized)
})

onBeforeUnmount(() => {
  window.removeEventListener('auth:unauthorized', handleUnauthorized)
})
</script>

<template>
  <RouterView v-if="useAuthLayout" />
  <SidebarLayout v-else />
  <ToastCenter />
  <ConfirmDialog />
</template>
