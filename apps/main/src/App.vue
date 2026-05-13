<script setup lang="ts">
/**
 * Top-level application shell — a layout switcher.
 *
 * Dispatches to the correct layout based on route.meta.layout:
 *
 *   - 'auth' | 'error' → AuthLayout (centred-card, no agency context)
 *   - 'agency'          → AgencyLayout (sidebar + topbar + user menu)
 *   - default ('app')   → bare v-app (catch-all for Sprint 0 stubs)
 *
 * Chunk 2: AgencyLayout added. ThemeToggle removed from the bare v-app
 * branch — it now lives exclusively in AgencyLayout's user menu. The
 * standalone ThemeToggle chrome div that lived here in chunk 8.2 is gone;
 * the component is still available at the same import path for the
 * AgencyLayout consumer.
 *
 * AuthLayout.vue also loses its ThemeToggle in Chunk 2 (see F6).
 *
 * Invariant: only ONE <v-app> is mounted per route. AuthLayout and
 * AgencyLayout each own their own <v-app> wrapper — this file uses
 * v-if / v-else-if / v-else to ensure exactly one is active.
 */

import { computed } from 'vue'
import { useRoute } from 'vue-router'

import AgencyLayout from '@/modules/agency/layouts/AgencyLayout.vue'
import AuthLayout from '@/modules/auth/layouts/AuthLayout.vue'

const route = useRoute()

const layout = computed<'auth' | 'agency' | 'error' | 'app'>(() => route.meta.layout ?? 'app')
</script>

<template>
  <AuthLayout v-if="layout === 'auth' || layout === 'error'">
    <router-view />
  </AuthLayout>
  <AgencyLayout v-else-if="layout === 'agency'">
    <router-view />
  </AgencyLayout>
  <v-app v-else>
    <v-main>
      <router-view />
    </v-main>
  </v-app>
</template>
