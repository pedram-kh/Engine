<script setup lang="ts">
/**
 * Top-level application shell — a layout switcher.
 *
 * Dispatches to the correct layout based on route.meta.layout:
 *
 *   - 'auth' | 'error'  → AuthLayout (centred-card, no agency context)
 *   - 'agency'          → AgencyLayout (sidebar + topbar + user menu)
 *   - 'onboarding'      → OnboardingLayout (wizard chrome — progress
 *                         indicator + "Save and exit"); Sprint 3
 *                         Chunk 3 sub-step 2.
 *   - 'creator'         → CreatorDashboardLayout (creator post-submit
 *                         shell — top bar + user menu, no sidebar);
 *                         Sprint 3 Chunk 3 sub-step 2.
 *   - default ('app')   → bare v-app (catch-all for Sprint 0 stubs)
 *
 * Chunk 2: AgencyLayout added. ThemeToggle removed from the bare v-app
 * branch — it now lives exclusively in AgencyLayout / CreatorDashboardLayout's
 * user menu (CreatorDashboardLayout mirrors the agency pattern).
 *
 * Sprint 3 Chunk 3 sub-step 2: OnboardingLayout + CreatorDashboardLayout
 * added per Decision 5.14 (parallel to AgencyLayout). Both own their
 * own `<v-app>` wrapper — App.vue's v-if/v-else-if chain ensures
 * exactly one is active (single-`<v-app>` invariant from chunk 6.8).
 */

import { computed } from 'vue'
import { useRoute } from 'vue-router'

import AgencyLayout from '@/modules/agency/layouts/AgencyLayout.vue'
import AuthLayout from '@/modules/auth/layouts/AuthLayout.vue'
import CreatorDashboardLayout from '@/modules/creators/layouts/CreatorDashboardLayout.vue'
import OnboardingLayout from '@/modules/onboarding/layouts/OnboardingLayout.vue'

const route = useRoute()

const layout = computed<'auth' | 'agency' | 'onboarding' | 'creator' | 'error' | 'app'>(
  () => route.meta.layout ?? 'app',
)
</script>

<template>
  <AuthLayout v-if="layout === 'auth' || layout === 'error'">
    <router-view />
  </AuthLayout>
  <AgencyLayout v-else-if="layout === 'agency'">
    <router-view />
  </AgencyLayout>
  <OnboardingLayout v-else-if="layout === 'onboarding'">
    <router-view />
  </OnboardingLayout>
  <CreatorDashboardLayout v-else-if="layout === 'creator'">
    <router-view />
  </CreatorDashboardLayout>
  <v-app v-else>
    <v-main>
      <router-view />
    </v-main>
  </v-app>
</template>
