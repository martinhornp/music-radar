import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// In development, /api is proxied to the local PHP server (Docker on :8080
// by default). Override with API_PROXY_TARGET if PHP runs elsewhere.
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://localhost:8080'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      // Keep the browser's Host header, so the API's same-origin check
      // (feedback writes) sees the dev site just like production.
      '/api': { target: apiTarget, changeOrigin: false },
    },
  },
})
