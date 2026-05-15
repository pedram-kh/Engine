<script setup lang="ts">
/**
 * OnboardingLayout — the wizard chrome (Sprint 3 Chunk 3 sub-step 2).
 *
 * Structure (per `docs/01-UI-UX.md § 11` "Onboarding (creator-facing)"):
 *
 *   v-app-bar (top, fixed)
 *     - Brand mark
 *     - Locale switcher
 *     - "Save and exit" off-ramp (from Step 2 onward — Q-wizard-4 (b)+(a))
 *   v-main
 *     - Progress indicator (vertical stepper at left on desktop, top
 *       on mobile, dispatched via CSS breakpoint)
 *     - Wizard body region (the routed step page)
 *
 * Layout invariants:
 *   - Owns its own `<v-app>` per the App.vue dispatch + chunk-6.8
 *     single-v-app rule.
 *   - The progress indicator is rendered by `OnboardingProgress.vue`
 *     and SOURCES its step status from the store's `stepCompletion`
 *     getter + `flags`. Skipped steps (flag-OFF) render "Skipped"
 *     per Decision E1=c.
 *   - The "Save and exit" link is HIDDEN on `onboarding.welcome-back`
 *     (the user hasn't started a step yet) and otherwise visible
 *     from Step 2 onward (Q-wizard-4 lock).
 *
 * Defense-in-depth on the bootstrap path: if the route mounts and the
 * store is not yet bootstrapped (a freshly-injected guard order),
 * the layout drives a bootstrap inline so the chrome never renders
 * with stale step state. The `requireOnboardingAccess` guard already
 * fires `bootstrap()`, so this is the belt-and-suspenders branch.
 */

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { buildLocaleOptions } from '@/modules/auth/layouts/localeOptions'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import OnboardingProgress from '../components/OnboardingProgress.vue'

const { t, locale, availableLocales } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const onboardingStore = useOnboardingStore()

const { isBootstrapped, bootstrapStatus } = storeToRefs(onboardingStore)
const { isLoggingOut } = storeToRefs(authStore)

const localeOptions = buildLocaleOptions(availableLocales, t)
const userMenuOpen = ref(false)

const showSaveAndExit = computed(() => route.name !== 'onboarding.welcome-back')

onMounted(async () => {
  if (!isBootstrapped.value && bootstrapStatus.value === 'idle') {
    await onboardingStore.bootstrap()
  }
})

async function saveAndExit(): Promise<void> {
  userMenuOpen.value = false
  await authStore.logout()
  await router.push({ name: 'auth.sign-in', query: { reason: 'saved' } })
}
</script>

<template>
  <v-app data-test="onboarding-layout">
    <v-app-bar elevation="1" data-test="onboarding-topbar">
      <div class="d-flex align-center px-4">
        <v-icon icon="mdi-lightning-bolt" color="primary" size="small" class="mr-2" />
        <span class="text-subtitle-1 font-weight-bold" data-test="onboarding-brand">
          {{ t('app.title') }}
        </span>
      </div>

      <v-spacer />

      <v-select
        v-model="locale"
        :items="localeOptions"
        :label="t('app.locale.switcher')"
        item-title="title"
        item-value="value"
        density="compact"
        variant="outlined"
        hide-details
        class="onboarding-topbar__locale mx-3"
        data-test="onboarding-locale-switcher"
      />

      <v-btn
        v-if="showSaveAndExit"
        variant="text"
        class="text-none"
        :disabled="isLoggingOut"
        data-test="save-and-exit-btn"
        @click="saveAndExit"
      >
        <v-icon icon="mdi-content-save-outline" start />
        {{ t('creator.ui.wizard.actions.save_and_exit') }}
      </v-btn>
    </v-app-bar>

    <v-main data-test="onboarding-main">
      <v-container class="onboarding-container pa-6">
        <div class="onboarding-layout__shell">
          <aside class="onboarding-layout__progress" data-test="onboarding-progress">
            <OnboardingProgress />
          </aside>
          <section class="onboarding-layout__body elevation-1" data-test="onboarding-body">
            <slot />
          </section>
        </div>
      </v-container>
    </v-main>
  </v-app>
</template>

<style scoped>
.onboarding-container {
  max-width: 1200px;
}

.onboarding-topbar__locale {
  max-width: 160px;
}

.onboarding-layout__shell {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 2rem;
  align-items: start;
}

.onboarding-layout__progress {
  position: sticky;
  top: 1rem;
}

.onboarding-layout__body {
  background-color: rgb(var(--v-theme-surface));
  border-radius: 8px;
  padding: 2rem;
  /* Elevation sourced via the Vuetify `elevation-1` utility class on
   * the consumer site — the architecture invariant disallows raw
   * rgba literals here (chunk 8.1 / no-hard-coded-colors.spec.ts). */
}

@media (max-width: 960px) {
  .onboarding-layout__shell {
    grid-template-columns: 1fr;
  }

  .onboarding-layout__progress {
    position: static;
  }
}
</style>
