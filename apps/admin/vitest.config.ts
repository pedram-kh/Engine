import { fileURLToPath } from 'node:url'
import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config'

export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      include: ['tests/unit/**/*.{spec,test}.ts'],
      exclude: ['tests/e2e/**', 'node_modules/**'],
      globals: true,
      root: fileURLToPath(new URL('./', import.meta.url)),
      setupFiles: ['./tests/unit/setup.ts'],
      server: {
        deps: {
          inline: ['vuetify'],
        },
      },
    },
  }),
)
