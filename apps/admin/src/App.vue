<script setup lang="ts">
/**
 * Top-level admin application shell — a layout switcher.
 *
 * Mirror of `apps/main/src/App.vue` (chunk 6.8). The three
 * `meta.layout` values declared in
 * `apps/admin/src/modules/auth/routes.ts` map as follows:
 *
 *   - `'auth'`  → centred-card layout via `AuthLayout.vue` (sign-in
 *                 + 2FA enrol/verify/disable routes).
 *   - `'error'` → reuses `AuthLayout.vue` (bootstrap-error route is a
 *                 single message, well-suited to the same frame).
 *   - `'app'`   → bare `<v-app><v-main>` shell hosting the routed
 *                 page. Dashboard / settings placeholders provide
 *                 their own surface inside the slot. A thin top-right
 *                 chrome line carries the chunk-8.2 `<ThemeToggle />`
 *                 so authenticated admins have a visible toggle until
 *                 a future sprint's admin nav surface lands and
 *                 consumes the same component.
 *
 * `AuthLayout.vue` owns its own `<v-app>` (chunk 7.5), so this file
 * MUST NOT wrap auth/error routes in another `<v-app>` — Vuetify
 * warns and the second app's theme tokens never resolve. The
 * conditional `v-if` / `v-else` ensures only one `<v-app>` is mounted
 * per route.
 *
 * Initial render before route resolution falls through to the `'app'`
 * branch: `meta.layout` is `undefined` for the `currentRoute` placeholder
 * Vue Router exposes before the first navigation, and the bare shell
 * is the safe default.
 *
 * Theme bootstrap (chunk 8.2): App.vue is the lifecycle hook that
 * runs in EVERY route's setup() — auth, app, error. Mounting
 * `<ThemeToggle />` here in the app-layout branch is enough for the
 * authenticated surface; on auth routes the toggle's wrapping
 * AuthLayout.vue mounts its own copy. The composable singleton
 * means both mountings share the same reactive state.
 */

import { computed } from 'vue'
import { useRoute } from 'vue-router'

import AuthLayout from '@/modules/auth/layouts/AuthLayout.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'

const route = useRoute()

const layout = computed<'auth' | 'app' | 'error'>(() => route.meta.layout ?? 'app')
</script>

<template>
  <AuthLayout v-if="layout === 'auth' || layout === 'error'">
    <router-view />
  </AuthLayout>
  <v-app v-else>
    <v-main>
      <div class="d-flex justify-end pa-2" data-test="app-chrome">
        <ThemeToggle />
      </div>
      <router-view />
    </v-main>
  </v-app>
</template>
