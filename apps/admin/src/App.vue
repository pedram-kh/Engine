<script setup lang="ts">
/**
 * Top-level admin application shell.
 *
 * Sub-chunk 7.4 ships the router infrastructure but not the auth pages
 * or the `AuthLayout`. This shell is therefore a minimal
 * `<v-app><v-main><router-view /></v-main></v-app>` frame: it lets the
 * router resolve and render the placeholder pages without the
 * route-meta layout switching main's `App.vue` performs (chunk 6.8).
 *
 * Sub-chunk 7.5 will:
 *   - Introduce `apps/admin/src/modules/auth/layouts/AuthLayout.vue`.
 *   - Expand this shell into a `meta.layout`-driven switcher that
 *     mounts `AuthLayout` for `'auth'` / `'error'` routes and the bare
 *     `<v-app>` for `'app'` routes — exact mirror of main's chunk-6.8
 *     `App.vue`.
 *
 * The route `meta.layout` field is already declared in
 * `apps/admin/src/modules/auth/routes.ts` so 7.5's expansion is a
 * template change only, not a route-table change.
 */
</script>

<template>
  <v-app>
    <v-main>
      <router-view />
    </v-main>
  </v-app>
</template>
