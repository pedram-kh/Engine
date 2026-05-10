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
      // Scoped to the auth module + the auth-adjacent core wiring
      // (router + api) added in chunk 6.5. Page components added in
      // 6.6 and 6.7 extend this include set further.
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
          // `auth.api.ts` is a thin re-export of the singleton from
          // `core/api`. The store tests mock the module entirely
          // (so `core/api` is never loaded under jsdom — that file
          // hits `import.meta.env.VITE_API_BASE_URL`). The contract
          // is verified by typecheck rather than runtime coverage.
          'src/modules/auth/api/auth.api.ts',
          // `AuthLayout.vue` is a pure structural SFC: brand mark,
          // locale switcher, slot. v8's function-coverage instrumentation
          // for `<script setup>` bookkeeps the compiled-setup wrapper
          // as a function whose body Vue never calls in a way v8 can
          // observe — every other page .vue file under chunk 6.6 has
          // its own user-defined `onSubmit()` / `onMounted` etc. that
          // anchors the function-coverage count, but the layout has
          // none. The substantive logic (locale-option construction)
          // was extracted to `localeOptions.ts` precisely so it could
          // be 100%-line+function tested separately. The chunk-6.4
          // "exclusion + guard pattern" applies: exclude the SFC
          // from runtime coverage, AND keep the file-size guard in
          // `tests/unit/architecture/auth-layout-shape.spec.ts` so a
          // future refactor that stuffs real logic back into the SFC
          // is loud at CI time.
          'src/modules/auth/layouts/AuthLayout.vue',
          // `routes.ts` is a declarative route table — every route's
          // `component` field is a `() => import(...)` arrow that v8
          // never invokes under the unit-test runner (no
          // `<router-view>` renders the actual component). The
          // routing behaviour itself is covered by the dispatcher
          // tests in `tests/unit/core/router/index.spec.ts` and by
          // the guard branch tests; the lazy-loader bodies are pure
          // data and deliberately untested. Architecture-level
          // protection on imports lives in
          // `tests/unit/architecture/no-direct-router-imports.spec.ts`.
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
