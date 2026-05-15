<script setup lang="ts">
/**
 * Step7PayoutPage — wizard Step 7 (Payout method).
 *
 * Sprint 3 Chunk 3 sub-step 7.
 *
 * Mirrors Step5KycPage's two-branch shape but targets the
 * `creator_payout_method_enabled` flag and the
 * `useVendorBounce('payout')` saga.
 *
 *   - flag ON: Stripe Connect onboarding via the hosted flow.
 *     `payout_method_set` flips to true once the webhook lands.
 *   - flag OFF: skipped-with-explanation; backend stamps the step
 *     complete at submit time (Q-flag-off-1).
 */

import { ApiError } from '@catalyst/api-client'
import { PayoutMethodStatus } from '@catalyst/ui'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { useFeatureFlags } from '../composables/useFeatureFlags'
import { useVendorBounce } from '../composables/useVendorBounce'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()
const { payout: payoutFlag } = useFeatureFlags()
const bounce = useVendorBounce('payout')

const initiateErrorKey = ref<string | null>(null)

const isSet = computed(() => store.creator?.attributes.payout_method_set ?? false)
const statusLabel = computed(() =>
  t(
    isSet.value
      ? 'creator.ui.wizard.steps.payout.status_set'
      : 'creator.ui.wizard.steps.payout.status_unset',
  ),
)

const pollAnnounce = computed(() => {
  if (bounce.status.value === 'waiting') {
    return t('creator.ui.wizard.vendor_bounce.waiting_description')
  }
  if (bounce.status.value === 'timeout') {
    return t('creator.ui.wizard.vendor_bounce.timeout_description')
  }
  return ''
})

async function beginSetup(): Promise<void> {
  initiateErrorKey.value = null
  try {
    const response = await store.initiatePayout()
    window.location.href = response.data.onboarding_url
  } catch (error) {
    initiateErrorKey.value =
      error instanceof ApiError ? error.code : 'creator.ui.errors.upload_failed'
  }
}

async function advance(): Promise<void> {
  await router.push({ name: 'onboarding.contract' })
}
</script>

<template>
  <section class="payout-step" data-testid="step-payout">
    <header class="payout-step__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.payout.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.payout.description') }}
      </p>
    </header>

    <div v-if="payoutFlag.enabled" class="payout-step__body" data-testid="payout-flag-on">
      <div class="payout-step__status-line">
        <span class="payout-step__status-label">
          {{ t('creator.ui.wizard.steps.payout.current_status') }}
        </span>
        <PayoutMethodStatus :is-set="isSet" :label="statusLabel" />
      </div>

      <v-btn
        v-if="!isSet"
        color="primary"
        :loading="store.isLoadingPayout"
        :disabled="store.isLoadingPayout || bounce.isPolling.value"
        data-testid="payout-begin"
        @click="beginSetup"
      >
        {{ t('creator.ui.wizard.steps.payout.begin_button') }}
      </v-btn>

      <div
        v-if="initiateErrorKey"
        role="alert"
        class="payout-step__error"
        data-testid="payout-initiate-error"
      >
        {{ t(initiateErrorKey) }}
      </div>
    </div>

    <div v-else class="payout-step__body" data-testid="payout-flag-off">
      <v-alert
        type="info"
        variant="tonal"
        :title="t('creator.ui.wizard.steps.payout.skipped_title')"
      >
        {{ t(payoutFlag.skipExplanationKey) }}
      </v-alert>
    </div>

    <div class="payout-step__sr-status" role="status" aria-live="polite" aria-atomic="true">
      {{ pollAnnounce }}
    </div>

    <div class="payout-step__actions">
      <v-btn
        color="primary"
        variant="tonal"
        :disabled="!isSet && payoutFlag.enabled"
        data-testid="payout-advance"
        @click="advance"
      >
        {{ t('creator.ui.wizard.actions.save_and_continue') }}
      </v-btn>
    </div>
  </section>
</template>

<style scoped>
.payout-step {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 720px;
}

.payout-step__status-line {
  display: flex;
  align-items: center;
  gap: 12px;
}

.payout-step__status-label {
  font-weight: 500;
}

.payout-step__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.payout-step__actions {
  display: flex;
  justify-content: flex-end;
}

.payout-step__sr-status {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
