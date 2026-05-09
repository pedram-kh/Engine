import { fileURLToPath } from 'node:url'
import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config'

export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      include: ['tests/unit/**/*.{spec,test}.ts', 'src/**/*.{spec,test}.ts'],
      exclude: ['tests/e2e/**', 'node_modules/**'],
      globals: true,
      root: fileURLToPath(new URL('./', import.meta.url)),
      setupFiles: ['./tests/unit/setup.ts'],
      server: {
        deps: {
          inline: ['vuetify'],
        },
      },
      // Auth-flow coverage gate per docs/02-CONVENTIONS.md § 4.3 +
      // docs/07-TESTING.md § 4.3: 100% line coverage on auth.
      // Scoped to the auth module so unrelated unflagged scaffolding
      // does not fail the gate; UI components landing in 6.6/6.7 will
      // extend this include set.
      coverage: {
        provider: 'v8',
        reporter: ['text', 'lcov'],
        include: ['src/modules/auth/**/*.ts'],
        exclude: [
          'src/modules/auth/**/*.spec.ts',
          'src/modules/auth/**/*.test.ts',
          // `auth.api.ts` is a thin re-export of the singleton from
          // `core/api`. The store tests mock the module entirely
          // (so `core/api` is never loaded under jsdom — that file
          // hits `import.meta.env.VITE_API_BASE_URL`). The contract
          // is verified by typecheck rather than runtime coverage.
          'src/modules/auth/api/auth.api.ts',
        ],
        thresholds: {
          lines: 100,
          statements: 100,
          functions: 100,
          branches: 100,
        },
      },
    },
  }),
)
