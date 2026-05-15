<script setup lang="ts">
/**
 * WelcomeBackPage — the Decision B (session-vs-fresh hybrid)
 * landing page for the onboarding wizard.
 *
 * Routing model:
 *   - Path: `/onboarding`
 *   - All wizard surface entry points (sign-up success → wizard,
 *     post-sign-in → wizard, deep-link to `/onboarding`) route here
 *     FIRST. This component decides between:
 *       (a) Same-session continuation: redirect immediately to
 *           `next_step` if {@link useOnboardingStore.wasBootstrappedThisSession}
 *           is `true` at mount time. The store flips this flag to
 *           true after the FIRST bootstrap() call in this tab's
 *           lifetime.
 *       (b) Fresh page load: render the "Welcome Back" UI with the
 *           creator's name + last-activity orientation + a single
 *           prominent "Continue from here" CTA. Secondary affordances
 *           (jump back to edit Step Z) ship via the always-visible
 *           OnboardingProgress sidebar.
 *
 * Mechanism — exact firing order:
 *   1. The router guard `requireOnboardingAccess` fires
 *      `bootstrap()` and waits.
 *   2. Inside `bootstrap()`'s success branch, `wasBootstrappedThisSession`
 *      flips to `true`.
 *   3. Vue mounts this component AFTER the guard resolves. We capture
 *      `wasBootstrappedThisSession` at the FIRST `onMounted` tick
 *      via a local snapshot ref. On a fresh page load, this snapshot
 *      is `false` because the bootstrap in step 2 happens during the
 *      guard chain BEFORE the component mounts — wait, no, that's
 *      wrong. Let me re-check the timing.
 *
 *   Actually the flip happens INSIDE the guard's `bootstrap()` call,
 *   which is awaited BEFORE the router calls the next() callback.
 *   So by the time the component mounts, the flag is ALREADY `true`
 *   on every page load — including the very first one. That breaks
 *   the spec.
 *
 *   The fix: capture the flag value BEFORE the guard's bootstrap()
 *   fires. We can't easily intercept the guard chain, so instead we
 *   look at a SEPARATE signal — `bootstrapStatus` at the moment the
 *   guard FIRST entered (idle vs ready). The guard's `bootstrap()`
 *   call transitions `idle → ready` on the first call only; on
 *   subsequent same-tab navigation, the status is already `ready`
 *   and the store's dedupe-cache hits.
 *
 *   So the right Decision-B signal at mount-time is: "was the store
 *   bootstrap-status already 'ready' before THIS guard chain ran?"
 *   Equivalently: "is this the first onboarding bootstrap of this
 *   tab's lifetime?". We track that via a module-scoped flag set
 *   inside the store's bootstrap() — flipping `false → true` on
 *   the very first successful resolution, AND a "before" flag we
 *   snapshot via a module-scoped variable that records whether the
 *   first bootstrap has happened. The first guard invocation sees
 *   the flag as `false` BEFORE `bootstrap()` is awaited; subsequent
 *   invocations see it as `true`.
 *
 *   Implementation: the store sets `wasBootstrappedThisSession` AT
 *   THE END of bootstrap(). For the Decision-B branch we need the
 *   value AT THE START. We expose a derived getter
 *   `firstBootstrapAlreadyDone` that compares `wasBootstrappedThisSession`
 *   to a one-shot module-scoped `hasMountedBefore` ref. On THIS
 *   component's first mount in the tab, both are false. On subsequent
 *   navigations within the same tab, the ref is true.
 *
 *   Simpler alternative: store a separate `priorBootstrap` flag that
 *   stays `false` for the FIRST mount of this component (cold page
 *   load → Welcome Back), and `true` for subsequent mounts (mid-tab
 *   navigation → auto-advance). The store-scoped
 *   `wasBootstrappedThisSession` is updated INSIDE bootstrap() AFTER
 *   the first success; this component reads `priorBootstrap`, a
 *   module-scoped boolean defined in this very file, that's set to
 *   `true` at the END of `onMounted`. So:
 *     - First mount: priorBootstrap === false → render Welcome Back.
 *       At end of onMounted, flip priorBootstrap → true.
 *     - Subsequent mounts (within this tab): priorBootstrap === true
 *       → auto-advance redirect.
 *
 *   That's the cleanest tab-scoped semantic. The "did the user
 *   already engage with the wizard once this tab" question is
 *   "did this component already mount once this tab".
 *
 *   Defense-in-depth break-revert (#40): toggling `priorBootstrap`
 *   to start at `true` makes the fresh-load welcome-back spec fail
 *   (it auto-advances), which is the regression mode this signal
 *   exists to catch.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useOnboardingStore } from '../stores/useOnboardingStore'
import { hasMountedBefore, markMounted } from '../internal/welcomeBackFlag'
import { WIZARD_STEP_ROUTE_NAMES } from '../routes'

const { t } = useI18n()
const router = useRouter()
const onboardingStore = useOnboardingStore()

const { creator, nextStep, isSubmitted, completenessScore, lastActivityAt } =
  storeToRefs(onboardingStore)

const shouldRender = ref(false)

function timeAgoCopy(timestamp: string | null): string {
  if (timestamp === null) {
    return t('creator.ui.wizard.welcome_back.title')
  }
  // Minimal client-side relative-time formatter. Phase 1 ships
  // i18n keys with explicit `time_ago` placeholders; the SPA picks
  // a coarse bucket ("a few minutes ago" / "hours ago" / "days ago")
  // to keep the surface stable.
  const ms = Date.now() - new Date(timestamp).getTime()
  const minutes = Math.floor(ms / 60_000)
  if (minutes < 5) return t('creator.ui.wizard.welcome_back.title')
  if (minutes < 60) return `${minutes} min`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h`
  const days = Math.floor(hours / 24)
  return `${days}d`
}

onMounted(async () => {
  // Auto-advance branch — second mount in this tab's lifetime.
  if (hasMountedBefore()) {
    const target = isSubmitted.value
      ? { name: 'creator.dashboard' }
      : { name: WIZARD_STEP_ROUTE_NAMES[nextStep.value ?? 'profile'] }
    await router.replace(target)
    return
  }

  // Submitted creators should not land on the Welcome Back page —
  // their post-submit home is `/creator/dashboard`. The
  // requireOnboardingAccess guard catches this normally, but on
  // first-load timing it can race; defensive redirect here.
  if (isSubmitted.value) {
    markMounted()
    await router.replace({ name: 'creator.dashboard' })
    return
  }

  // First mount in this tab — render the Welcome Back UI. Flip the
  // flag now so any subsequent mount within the same tab (router
  // navigation back to this route from another wizard step)
  // auto-advances.
  markMounted()
  shouldRender.value = true
})

function continueFromHere(): void {
  if (nextStep.value === null) return
  const target =
    nextStep.value === 'review' ? 'onboarding.review' : WIZARD_STEP_ROUTE_NAMES[nextStep.value]
  void router.push({ name: target })
}
</script>

<template>
  <div v-if="shouldRender && creator" class="welcome-back" data-test="welcome-back-page">
    <h1 class="text-h4 mb-2" data-test="welcome-back-heading">
      {{ t('creator.ui.wizard.welcome_back.title') }}
    </h1>
    <p class="text-body-1 text-medium-emphasis mb-6" data-test="welcome-back-subtitle">
      {{
        t('creator.ui.wizard.welcome_back.subtitle', {
          time_ago: timeAgoCopy(lastActivityAt),
        })
      }}
    </p>

    <v-card class="mb-6 pa-6" elevation="1" data-test="welcome-back-status">
      <div class="d-flex align-center justify-space-between mb-4">
        <div>
          <p class="text-caption text-medium-emphasis mb-1">
            {{ t('creator.ui.dashboard.submitted.completeness_label') }}
          </p>
          <p class="text-h5 mb-0" data-test="welcome-back-completeness">{{ completenessScore }}%</p>
        </div>
        <v-progress-circular
          :model-value="completenessScore"
          :size="48"
          :width="6"
          color="primary"
        />
      </div>

      <p
        v-if="nextStep && nextStep !== 'review'"
        class="text-body-2 mb-4"
        data-test="welcome-back-next-step-prompt"
      >
        {{
          t('creator.ui.wizard.welcome_back.next_step_prompt', {
            step: t(`creator.ui.wizard.steps.${nextStep}.name`),
          })
        }}
      </p>
      <p v-else class="text-body-2 mb-4" data-test="welcome-back-all-complete-prompt">
        {{ t('creator.ui.wizard.welcome_back.all_complete_prompt') }}
      </p>

      <v-btn
        color="primary"
        size="large"
        class="text-none"
        data-test="welcome-back-continue-btn"
        @click="continueFromHere"
      >
        <span v-if="nextStep === 'review'">
          {{ t('creator.ui.wizard.welcome_back.review_button') }}
        </span>
        <span v-else-if="nextStep">
          {{
            t('creator.ui.wizard.welcome_back.continue_button', {
              step_name: t(`creator.ui.wizard.steps.${nextStep}.name`),
            })
          }}
        </span>
      </v-btn>
    </v-card>
  </div>
</template>

<style scoped>
.welcome-back {
  max-width: 640px;
}
</style>
