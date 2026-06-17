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
import type { CreatorWizardStepId } from '@catalyst/api-client'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import OnboardingProgress from '../components/OnboardingProgress.vue'
import AnimatedWizardChrome, { type WizardChromeStep } from '../components/AnimatedWizardChrome.vue'
import { resolveStepStatus } from '../composables/useFeatureFlags'
import { WIZARD_STEP_ROUTE_NAMES } from '../routes'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const onboardingStore = useOnboardingStore()
const display = useDisplay()

const { isBootstrapped, bootstrapStatus, creator, stepCompletion, flags, nextStep } =
  storeToRefs(onboardingStore)
const { isLoggingOut } = storeToRefs(authStore)

const { selectLocale } = useLocaleSwitch()
const localeOptions = buildLocaleOptions()
const userMenuOpen = ref(false)

const TOTAL_STEPS = 9

/** Ordered rail: implicit Step 1 (account) + the eight backend steps. */
const RAIL_STEPS: ReadonlyArray<{ id: string; stepId: CreatorWizardStepId | null }> = [
  { id: 'account_created', stepId: null },
  { id: 'profile', stepId: 'profile' },
  { id: 'social', stepId: 'social' },
  { id: 'portfolio', stepId: 'portfolio' },
  { id: 'kyc', stepId: 'kyc' },
  { id: 'tax', stepId: 'tax' },
  { id: 'payout', stepId: 'payout' },
  { id: 'contract', stepId: 'contract' },
  { id: 'review', stepId: 'review' },
]

const ROUTE_TO_INDEX: Record<string, number> = {
  'onboarding.profile': 1,
  'onboarding.social': 2,
  'onboarding.portfolio': 3,
  'onboarding.kyc': 4,
  'onboarding.tax': 5,
  'onboarding.payout': 6,
  'onboarding.contract': 7,
  'onboarding.review': 8,
}

const STEP_ID_TO_INDEX: Record<CreatorWizardStepId, number> = {
  profile: 1,
  social: 2,
  portfolio: 3,
  kyc: 4,
  tax: 5,
  payout: 6,
  contract: 7,
  review: 8,
}

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
    return nextStep.value ? (STEP_ID_TO_INDEX[nextStep.value] ?? 1) : STEP_ID_TO_INDEX.review
  }
  return ROUTE_TO_INDEX[String(route.name)] ?? -1
})

const chromeSteps = computed<WizardChromeStep[]>(() =>
  RAIL_STEPS.map((row, i) => {
    const routeName = row.stepId === null ? null : (WIZARD_STEP_ROUTE_NAMES[row.stepId] as string)

    let status: WizardChromeStep['status']
    if (i === activeIndex.value) {
      status = 'active'
    } else if (row.stepId === null) {
      status = 'completed'
    } else {
      status = resolveStepStatus(row.stepId, stepCompletion.value[row.stepId] ?? false, flags.value)
    }

    const title =
      row.stepId === null
        ? t('creator.ui.wizard.steps.account_created.name')
        : t(`creator.ui.wizard.steps.${row.stepId}.name`)

    return {
      id: row.id,
      title,
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
