import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
const r = (p: string) => fileURLToPath(new URL(p, import.meta.url))
export default defineConfig({
  root: r('.'),
  plugins: [vue(), vuetify({ autoImport: true })],
  resolve: {
    alias: [
      { find: '@/core/platform/isNativePlatform', replacement: r('./native-stub.ts') },
      { find: '@', replacement: r('../src') },
      {
        find: 'firebase/messaging',
        replacement: r('../src/core/platform/push/firebaseMessagingStub.ts'),
      },
    ],
  },
  server: { port: 5199, strictPort: true, fs: { allow: [r('../../..')] } },
})
