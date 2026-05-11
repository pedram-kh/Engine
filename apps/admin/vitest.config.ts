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
      // SPA's auth module (sub-chunk 7.2), the router infrastructure
      // (sub-chunk 7.4: `src/core/router/**`), and the 401 policy
      // (sub-chunk 7.4: `src/core/api/**`). Mirrors
      // `apps/main/vitest.config.ts` chunk-6.5 gate scope.
      // Page components added in sub-chunk 7.5 will extend this
      // include set further.
      coverage: {
        provider: 'v8',
        reporter: ['text', 'lcov'],
        include: [
          'src/modules/auth/**/*.{ts,vue}',
          'src/core/router/**/*.ts',
          'src/core/api/**/*.ts',
        ],
        exclude: [
          'src/modules/auth/**/*.spec.ts',
          'src/modules/auth/**/*.test.ts',
          'src/core/router/**/*.spec.ts',
          'src/core/api/**/*.spec.ts',
          // `admin-auth.api.ts` is a thin re-export of the singleton
          // from `core/api`. The store tests mock the module entirely
          // (so `core/api` is never loaded under jsdom — that file
          // hits `import.meta.env.VITE_API_BASE_URL`). The contract
          // is verified by typecheck rather than runtime coverage,
          // and the chunk-6.4 "exclusion + guard pattern" applies:
          // the file-size + import/export-only guard lives in
          // `tests/unit/architecture/auth-api-reexport-shape.spec.ts`.
          'src/modules/auth/api/admin-auth.api.ts',
          // `routes.ts` is a declarative route table — every route's
          // `component` field is a `() => import(...)` arrow that v8
          // never invokes under the unit-test runner (no
          // `<router-view>` renders the actual component during the
          // dispatcher/guard tests). The routing behaviour itself is
          // covered by the dispatcher tests in
          // `tests/unit/core/router/index.spec.ts` and by the guard
          // branch tests; the lazy-loader bodies are pure data and
          // deliberately untested at the unit level. Architecture-level
          // protection on imports lives in
          // `tests/unit/architecture/no-direct-router-imports.spec.ts`
          // (chunk-6.4 exclusion + guard pattern, mirror of main's
          // chunk-6.5 decision).
          'src/modules/auth/routes.ts',
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
