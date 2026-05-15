<script setup lang="ts">
/**
 * Step8ContractPage — wizard Step 8 (Master agreement).
 *
 * Sprint 3 Chunk 3 sub-step 7.
 *
 *   - flag ON: full e-sign vendor flow. The page shows a
 *     ContractStatusBadge + "Open agreement to sign" CTA that
 *     calls `store.initiateContract()` and full-page navigates to
 *     the vendor URL.
 *   - flag OFF: renders `<ClickThroughAccept>` which sources its
 *     contract terms from the server-rendered endpoint
 *     (`GET /wizard/contract/terms`, sub-step 4). Once the
 *     creator accepts, the page emits an `accepted` event that
 *     advances the wizard.
 *
 * Decisions applied:
 *   - Decision E2=a (inline-scrollable click-through region).
 *   - Q-flag-off-2=(a) (click_through_accepted_at OR
 *     has_signed_master_contract both count as "step complete").
 *
 * a11y (F2=b): the polling state announces via an
 * `aria-live="polite"` region. The Continue button is disabled
 * until either signal is present.
 */

import { ApiError } from '@catalyst/api-client'
import { ContractStatusBadge } from '@catalyst/ui'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import ClickThroughAccept from '../components/ClickThroughAccept.vue'
import { useFeatureFlags } from '../composables/useFeatureFlags'
import { useVendorBounce } from '../composables/useVendorBounce'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()
const { contract: contractFlag } = useFeatureFlags()
const bounce = useVendorBounce('contract')

const initiateErrorKey = ref<string | null>(null)

const hasSigned = computed(() => store.creator?.attributes.has_signed_master_contract ?? false)
const clickThroughAccepted = computed(
  () =>
    store.creator?.attributes.click_through_accepted_at !== null &&
    store.creator?.attributes.click_through_accepted_at !== undefined,
)

const status = computed<'signed' | 'click_through_accepted' | 'none'>(() => {
  if (hasSigned.value) return 'signed'
  if (clickThroughAccepted.value) return 'click_through_accepted'
  return 'none'
})

const statusLabel = computed(() =>
  t(`creator.ui.wizard.steps.contract.status_labels.${status.value}`),
)

const isComplete = computed(() => hasSigned.value || clickThroughAccepted.value)

const pollAnnounce = computed(() => {
  if (bounce.status.value === 'waiting') {
    return t('creator.ui.wizard.vendor_bounce.waiting_description')
  }
  if (bounce.status.value === 'timeout') {
    return t('creator.ui.wizard.vendor_bounce.timeout_description')
  }
  return ''
})

async function beginSign(): Promise<void> {
  initiateErrorKey.value = null
  try {
    const response = await store.initiateContract()
    window.location.href = response.data.signing_url
  } catch (error) {
    initiateErrorKey.value =
      error instanceof ApiError ? error.code : 'creator.ui.errors.upload_failed'
  }
}

async function advance(): Promise<void> {
  await router.push({ name: 'onboarding.review' })
}

async function onClickThroughAccepted(): Promise<void> {
  await advance()
}
</script>

<template>
  <section class="contract-step" data-testid="step-contract">
    <header class="contract-step__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.contract.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.contract.description') }}
      </p>
    </header>

    <div v-if="contractFlag.enabled" class="contract-step__body" data-testid="contract-flag-on">
      <div class="contract-step__status-line">
        <span class="contract-step__status-label">
          {{ t('creator.ui.wizard.steps.contract.current_status') }}
        </span>
        <ContractStatusBadge :status="status" :label="statusLabel" />
      </div>

      <v-btn
        v-if="!isComplete"
        color="primary"
        :loading="store.isLoadingContract"
        :disabled="store.isLoadingContract || bounce.isPolling.value"
        data-testid="contract-begin"
        @click="beginSign"
      >
        {{ t('creator.ui.wizard.steps.contract.begin_button') }}
      </v-btn>

      <div
        v-if="initiateErrorKey"
        role="alert"
        class="contract-step__error"
        data-testid="contract-initiate-error"
      >
        {{ t(initiateErrorKey) }}
      </div>

      <div class="contract-step__actions">
        <v-btn
          color="primary"
          variant="tonal"
          :disabled="!isComplete"
          data-testid="contract-advance"
          @click="advance"
        >
          {{ t('creator.ui.wizard.actions.save_and_continue') }}
        </v-btn>
      </div>
    </div>

    <div v-else class="contract-step__body" data-testid="contract-flag-off">
      <ClickThroughAccept @accepted="onClickThroughAccepted" />
    </div>

    <div class="contract-step__sr-status" role="status" aria-live="polite" aria-atomic="true">
      {{ pollAnnounce }}
    </div>
  </section>
</template>

<style scoped>
.contract-step {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 720px;
}

.contract-step__status-line {
  display: flex;
  align-items: center;
  gap: 12px;
}

.contract-step__status-label {
  font-weight: 500;
}

.contract-step__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.contract-step__actions {
  display: flex;
  justify-content: flex-end;
}

.contract-step__sr-status {
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
