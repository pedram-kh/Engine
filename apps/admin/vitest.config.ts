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
      // docs/07-TESTING.md § 4.3: 100% line coverage on the admin
      // SPA's auth module (sub-chunk 7.2). Mirrors
      // `apps/main/vitest.config.ts` chunk-6.4 gate scope.
      // Router, guards, and page components added in sub-chunks
      // 7.4/7.5 will extend this include set.
      coverage: {
        provider: 'v8',
        reporter: ['text', 'lcov'],
        include: ['src/modules/auth/**/*.{ts,vue}'],
        exclude: [
          'src/modules/auth/**/*.spec.ts',
          'src/modules/auth/**/*.test.ts',
          // `admin-auth.api.ts` is a thin re-export of the singleton
          // from `core/api`. The store tests mock the module entirely
          // (so `core/api` is never loaded under jsdom — that file
          // hits `import.meta.env.VITE_API_BASE_URL`). The contract
          // is verified by typecheck rather than runtime coverage,
          // and the chunk-6.4 "exclusion + guard pattern" applies:
          // the file-size + import/export-only guard lives in
          // `tests/unit/architecture/auth-api-reexport-shape.spec.ts`.
          'src/modules/auth/api/admin-auth.api.ts',
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
