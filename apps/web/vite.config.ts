import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  // Ensure Vite config can consume values from .env files and shell env.
  const env = loadEnv(mode, process.cwd(), '')
  const srcRootWithSlash = `${fileURLToPath(new URL('./src', import.meta.url)).replace(/\\/g, '/')}/`
  const apiProxyTarget = env.VITE_PROXY_TARGET || 'http://localhost:8000'
  const allowedHosts = env.VITE_ALLOWED_HOSTS
    ? env.VITE_ALLOWED_HOSTS.split(',').map((host) => host.trim()).filter(Boolean)
    : ['localhost', '127.0.0.1', 'nginx']

  return {
    plugins: [vue()],
    resolve: {
      alias: {
        '@': srcRootWithSlash,
      },
    },
    server: {
      allowedHosts,
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
              // Keep chart libraries route-local by allowing Rollup to split
              // dynamic chart imports naturally, instead of forcing one global vendor chunk.
              return undefined
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
