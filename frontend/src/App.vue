<script setup lang="ts">
import { onMounted } from 'vue'
import SidebarLayout from '@/components/layout/SidebarLayout.vue'
import ToastCenter from '@/components/layout/ToastCenter.vue'
import ConfirmDialog from '@/components/layout/ConfirmDialog.vue'
import { useUiStore } from '@/stores/uiStore'

const uiStore = useUiStore()

onMounted(() => {
  uiStore.initTheme()
})
</script>

<template>
  <SidebarLayout />
  <ToastCenter />
  <ConfirmDialog />
</template>
