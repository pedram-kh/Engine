<script setup lang="ts">
/**
 * OnboardingLayout — the wizard chrome.
 *
 * Renders the brand app-bar (locale switcher + "Save and exit") and the
 * wizard body. The body is presented one of two ways:
 *
 *   - Desktop + motion allowed → `AnimatedWizardChrome` (Option A): an
 *     animated title stack on the left + an SVG frame around a full-size,
 *     scrollable content panel that holds the routed step page.
 *   - Mobile, reduced-motion, or the Welcome Back takeover → the original
 *     `OnboardingProgress` rail + plain body. This keeps the heavy,
 *     scroll-friendly form layout on small screens and honours
 *     `prefers-reduced-motion`.
 *
 * Invariants preserved from the prior implementation:
 *   - Owns its own `<v-app>` (App.vue single-`<v-app>` dispatch).
 *   - The aurora thin-accent on the app-bar (`var(--brand-aurora-gradient)`)
 *     — pinned by `aurora-surfacing.spec.ts`.
 *   - Step status SOURCES from the store's `stepCompletion` + `flags`
 *     (backend authoritative); skipped (flag-OFF) steps render "Skipped".
 *   - "Save and exit" hidden on `onboarding.welcome-back`.
 *   - Bootstrap belt-and-suspenders on mount.
 *   - `data-test` hooks: onboarding-layout / -topbar / -brand /
 *     -locale-switcher / save-and-exit-btn / -main / -progress / -body.
 */

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useDisplay } from 'vuetify'

import ImpersonationBanner from '@/modules/impersonation/components/ImpersonationBanner.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { buildLocaleOptions } from '@/modules/auth/layouts/localeOptions'
import { useLocaleSwitch } from '@/core/i18n/useLocaleSwitch'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import OnboardingProgress from '../components/OnboardingProgress.vue'
import AnimatedWizardChrome, { type WizardChromeStep } from '../components/AnimatedWizardChrome.vue'
import {
  VISIBLE_UX_STEPS,
  WIZARD_TOTAL_STEPS,
  resolveUxStepStatus,
  uxIndexForBackendStep,
  uxIndexForRoute,
  uxStepTitleKey,
} from '../composables/useWizardSteps'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const onboardingStore = useOnboardingStore()
const display = useDisplay()

const {
  isBootstrapped,
  bootstrapStatus,
  creator,
  stepCompletion,
  flags,
  clickThroughAccepted,
  nextStep,
} = storeToRefs(onboardingStore)
const { isLoggingOut } = storeToRefs(authStore)

const { selectLocale } = useLocaleSwitch()
const localeOptions = buildLocaleOptions()
const userMenuOpen = ref(false)

/**
 * Rail rows + numbering are DERIVED from {@link VISIBLE_UX_STEPS} (AH-003):
 * the account row, the visible wizard steps (social + portfolio merged
 * into "connections"; kyc/tax/payout hidden), and review. Indices into
 * that list drive both "Step X of N" and the animated chrome's geometry,
 * so a reversible-hide flip needs no edit here.
 */
const TOTAL_STEPS = WIZARD_TOTAL_STEPS

const showSaveAndExit = computed(() => route.name !== 'onboarding.welcome-back')

const isWelcomeBack = computed(() => route.name === 'onboarding.welcome-back')

const prefersReducedMotion = ref(false)
onMounted(() => {
  prefersReducedMotion.value =
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
})

/** Use the animated chrome on a roomy viewport, with motion allowed.
 *  The Welcome Back resume screen is included so there is no jarring
 *  switch between two designs — it renders inside the same chrome with
 *  its `next_step` highlighted on the rail. */
const useAnimatedChrome = computed(
  () => !display.smAndDown.value && !prefersReducedMotion.value && creator.value !== null,
)

/** On a step route the active row is that step; on the Welcome Back
 *  resume screen we highlight the creator's `next_step` so the rail
 *  already points at where they'll continue. */
const activeIndex = computed(() => {
  if (isWelcomeBack.value) {
    const reviewIndex = VISIBLE_UX_STEPS.findIndex((s) => s.id === 'review')
    return nextStep.value ? uxIndexForBackendStep(nextStep.value) : reviewIndex
  }
  return uxIndexForRoute(String(route.name))
})

const chromeSteps = computed<WizardChromeStep[]>(() =>
  VISIBLE_UX_STEPS.map((step, i) => {
    const routeName = step.routeName

    let status: WizardChromeStep['status']
    if (i === activeIndex.value) {
      status = 'active'
    } else {
      status = resolveUxStepStatus(
        step,
        stepCompletion.value,
        flags.value,
        clickThroughAccepted.value,
      )
    }

    return {
      id: step.id,
      title: t(uxStepTitleKey(step)),
      status,
      routeName,
      clickable: i < activeIndex.value && routeName !== null,
      positionLabel: t('creator.ui.wizard.progress.step_of', {
        current: i + 1,
        total: TOTAL_STEPS,
      }),
    }
  }),
)

function onNavigate(routeName: string): void {
  void router.push({ name: routeName })
}

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
    <ImpersonationBanner />

    <v-app-bar elevation="1" class="onboarding-topbar" data-test="onboarding-topbar">
      <div class="d-flex align-center px-4">
        <v-icon icon="mdi-lightning-bolt" color="primary" size="small" class="mr-2" />
        <span class="text-subtitle-1 font-weight-bold" data-test="onboarding-brand">
          {{ t('app.title') }}
        </span>
      </div>

      <v-spacer />

      <v-select
        :model-value="locale"
        :items="localeOptions"
        :label="t('app.locale.switcher')"
        item-title="title"
        item-value="value"
        density="compact"
        variant="outlined"
        hide-details
        class="onboarding-topbar__locale mx-3"
        data-test="onboarding-locale-switcher"
        @update:model-value="selectLocale"
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
      <!-- Option A: animated chrome (desktop, motion allowed, step route) -->
      <div v-if="useAnimatedChrome" class="onboarding-stage">
        <AnimatedWizardChrome
          :steps="chromeSteps"
          :active-index="activeIndex"
          :reduced-motion="prefersReducedMotion"
          @navigate="onNavigate"
        >
          <slot />
        </AnimatedWizardChrome>
      </div>

      <!-- Fallback: original rail + plain body (mobile / reduced-motion /
           Welcome Back takeover) -->
      <v-container v-else class="onboarding-container pa-6">
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

/* Aurora brand accent — a 2px aurora gradient line along the app-bar's
 * bottom edge (consumes the authored utility var, never a Vuetify
 * theme.color; pinned by aurora-surfacing.spec.ts). */
.onboarding-topbar {
  position: relative;
}

.onboarding-topbar::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 2px;
  background: var(--brand-aurora-gradient);
}

.onboarding-topbar__locale {
  max-width: 160px;
}

/* full-bleed animated stage fills the area below the app-bar */
.onboarding-stage {
  position: relative;
  height: calc(100dvh - 64px);
  min-height: 480px;
  overflow: hidden;
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
  border-radius: var(--radius-lg);
  padding: 2rem;
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
