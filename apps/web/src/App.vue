<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import SidebarLayout from '@/components/layout/SidebarLayout.vue'
import ToastCenter from '@/components/layout/ToastCenter.vue'
import ConfirmDialog from '@/components/layout/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/authStore'

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const useAuthLayout = computed(() => route.meta.layout === 'auth')

function handleUnauthorized() {
  authStore.clearSession()
  if (route.path === '/auth/login' || route.path === '/login') return
  router.replace({
    path: '/auth/login',
    query: { redirect: route.fullPath },
  })
}

onMounted(() => {
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
