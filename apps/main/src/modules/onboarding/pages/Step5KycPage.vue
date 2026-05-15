<script setup lang="ts">
/**
 * Step5KycPage — wizard Step 5 (Identity verification).
 *
 * Sprint 3 Chunk 3 sub-step 7.
 *
 * Two render branches depending on the
 * `kyc_verification_enabled` feature flag:
 *
 *   - flag ON (production): the page shows the current KYC
 *     status badge + a "Begin verification" CTA that calls
 *     `store.initiateKyc()` and full-page navigates to the
 *     vendor-hosted flow. When the creator returns via the
 *     redirect-bounce twin, the `useVendorBounce('kyc')` loop
 *     drives the visible status.
 *
 *   - flag OFF (Phase-1 mock-vendor sprint cadence): the page
 *     shows a "Skipped" badge with the localized explanation
 *     (Decision E1=a) and the advance button immediately
 *     navigates to the next step. The backend stamps
 *     `kyc_status='not_required'` at submit time.
 *
 * Decisions applied:
 *   - Decision E1=a (skipped-with-explanation flag-OFF surface).
 *   - Q-vendor-bounce-1=(a) (two-state polling UI: waiting +
 *     timeout — failure is also rendered via the bounce status).
 *
 * a11y (F2=b): the polling state announces via an
 * `aria-live="polite"` region. The vendor-bounce CTA carries
 * `:disabled` while loading.
 */

import { ApiError } from '@catalyst/api-client'
import { KycStatusBadge } from '@catalyst/ui'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { useFeatureFlags } from '../composables/useFeatureFlags'
import { useVendorBounce } from '../composables/useVendorBounce'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()
const { kyc: kycFlag } = useFeatureFlags()
const bounce = useVendorBounce('kyc')

const initiateErrorKey = ref<string | null>(null)

const status = computed(() => store.creator?.attributes.kyc_status ?? 'none')
const isVerified = computed(() => status.value === 'verified' || status.value === 'not_required')

const statusLabel = computed(() => t(`creator.ui.wizard.steps.kyc.status_labels.${status.value}`))

const pollAnnounce = computed(() => {
  if (bounce.status.value === 'waiting') {
    return t('creator.ui.wizard.vendor_bounce.waiting_description')
  }
  if (bounce.status.value === 'timeout') {
    return t('creator.ui.wizard.vendor_bounce.timeout_description')
  }
  if (bounce.status.value === 'failed') {
    return bounce.errorKey.value
      ? t(bounce.errorKey.value)
      : t('creator.ui.wizard.vendor_bounce.timeout_description')
  }
  return ''
})

async function beginVerification(): Promise<void> {
  initiateErrorKey.value = null
  try {
    const response = await store.initiateKyc()
    window.location.href = response.data.hosted_flow_url
  } catch (error) {
    initiateErrorKey.value =
      error instanceof ApiError ? error.code : 'creator.ui.errors.upload_failed'
  }
}

async function advance(): Promise<void> {
  await router.push({ name: 'onboarding.tax' })
}
</script>

<template>
  <section class="kyc-step" data-testid="step-kyc">
    <header class="kyc-step__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.kyc.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.kyc.description') }}
      </p>
    </header>

    <!-- flag ON: vendor-bounce surface -->
    <div v-if="kycFlag.enabled" class="kyc-step__body" data-testid="kyc-flag-on">
      <div class="kyc-step__status-line">
        <span class="kyc-step__status-label">
          {{ t('creator.ui.wizard.steps.kyc.current_status') }}
        </span>
        <KycStatusBadge :status="status" :label="statusLabel" />
      </div>

      <v-btn
        v-if="!isVerified"
        color="primary"
        :loading="store.isLoadingKyc"
        :disabled="store.isLoadingKyc || bounce.isPolling.value"
        data-testid="kyc-begin"
        @click="beginVerification"
      >
        {{ t('creator.ui.wizard.steps.kyc.begin_button') }}
      </v-btn>

      <div
        v-if="initiateErrorKey"
        role="alert"
        class="kyc-step__error"
        data-testid="kyc-initiate-error"
      >
        {{ t(initiateErrorKey) }}
      </div>
    </div>

    <!-- flag OFF: skipped-with-explanation -->
    <div v-else class="kyc-step__body" data-testid="kyc-flag-off">
      <v-alert type="info" variant="tonal" :title="t('creator.ui.wizard.steps.kyc.skipped_title')">
        {{ t(kycFlag.skipExplanationKey) }}
      </v-alert>
    </div>

    <div class="kyc-step__sr-status" role="status" aria-live="polite" aria-atomic="true">
      {{ pollAnnounce }}
    </div>

    <div class="kyc-step__actions">
      <v-btn
        color="primary"
        variant="tonal"
        :disabled="!isVerified && kycFlag.enabled"
        data-testid="kyc-advance"
        @click="advance"
      >
        {{ t('creator.ui.wizard.actions.save_and_continue') }}
      </v-btn>
    </div>
  </section>
</template>

<style scoped>
.kyc-step {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 720px;
}

.kyc-step__status-line {
  display: flex;
  align-items: center;
  gap: 12px;
}

.kyc-step__status-label {
  font-weight: 500;
}

.kyc-step__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.kyc-step__actions {
  display: flex;
  justify-content: flex-end;
}

.kyc-step__sr-status {
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
