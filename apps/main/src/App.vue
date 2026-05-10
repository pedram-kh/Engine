<script setup lang="ts">
/**
 * Top-level application shell — a layout switcher.
 *
 * Until chunk 6.8, this file rendered the Sprint-0 placeholder text
 * directly and never instantiated `<router-view />`. Vue Router was
 * registered in `main.ts` but its output was unreachable, which made
 * the SPA's runtime URL navigation a no-op (page-level component tests
 * via `mountAuthPage` worked because they bypass App.vue entirely).
 * The chunk 6.8 Playwright specs need real navigation, so this file
 * now wires the route table to a layout per `route.meta.layout`.
 *
 * The three `meta.layout` values declared in
 * `apps/main/src/modules/auth/routes.ts` map as follows:
 *
 *   - `'auth'`  → centred-card layout via `AuthLayout.vue` (every
 *                 sign-in / sign-up / verify-email / reset-password /
 *                 2FA route).
 *   - `'error'` → reuses `AuthLayout.vue` (the bootstrap-error route
 *                 is a single message + retry button, well-suited to
 *                 the same centred-card frame).
 *   - `'app'`   → bare `<v-app><v-main>` shell hosting the routed
 *                 page. The dashboard / settings placeholders provide
 *                 their own surface inside the slot.
 *
 * `AuthLayout.vue` owns its own `<v-app>` (chunk 6.6), so this file
 * MUST NOT wrap auth/error routes in another `<v-app>` — Vuetify
 * warns and the second app's theme tokens never resolve. The
 * conditional `v-if` / `v-else` ensures only one `<v-app>` is mounted
 * per route.
 *
 * Initial render before route resolution falls through to the `'app'`
 * branch: `meta.layout` is `undefined` for the `currentRoute` placeholder
 * Vue Router exposes before the first navigation, and the bare shell
 * is the safe default (an unmatched route under HTML5 history mode
 * resolves to a missing match, not to an auth route).
 */

import { computed } from 'vue'
import { useRoute } from 'vue-router'

import AuthLayout from '@/modules/auth/layouts/AuthLayout.vue'

const route = useRoute()

const layout = computed<'auth' | 'app' | 'error'>(() => route.meta.layout ?? 'app')
</script>

<template>
  <AuthLayout v-if="layout === 'auth' || layout === 'error'">
    <router-view />
  </AuthLayout>
  <v-app v-else>
    <v-main>
      <router-view />
    </v-main>
  </v-app>
</template>
