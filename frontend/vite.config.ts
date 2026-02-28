import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  // Ensure Vite config can consume values from .env files and shell env.
  const env = loadEnv(mode, process.cwd(), '')
  const apiProxyTarget = env.VITE_PROXY_TARGET || 'http://localhost:8000'

  return {
    plugins: [vue()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      port: 5173,
      proxy: {
        '/sanctum': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
        '/api': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
        '/storage': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
      },
    },
    build: {
      rollupOptions: {
        output: {
          manualChunks(id) {
            if (!id.includes('node_modules')) {
              return undefined
            }

            if (id.includes('echarts') || id.includes('zrender') || id.includes('vue-echarts')) {
              return 'vendor-charts'
            }

            if (id.includes('vue-router') || id.includes('pinia') || id.includes('/vue/')) {
              return 'vendor-vue'
            }

            if (id.includes('axios')) {
              return 'vendor-http'
            }

            return 'vendor'
          },
        },
      },
    },
  }
})
