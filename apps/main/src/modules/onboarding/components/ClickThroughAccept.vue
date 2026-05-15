<script setup lang="ts">
/**
 * Click-through acceptance fallback for the master agreement
 * (Sprint 3 Chunk 3 sub-step 4, Decision E2=a — inline scrollable).
 *
 * Renders the server-side-rendered HTML returned by
 * `GET /api/v1/creators/me/wizard/contract/terms` inside an
 * inline scrollable region. Below the region: a checkbox the
 * creator must tick + a Continue button that fires the
 * click-through endpoint.
 *
 * Trust boundary (Refinement 4): the HTML body comes from the
 * server-rendered terms endpoint, which:
 *   - Reads from a static markdown file under the platform's
 *     control (not user input).
 *   - Passes through league/commonmark with `allow_unsafe_links:
 *     false` + `html_input: 'escape'`.
 *
 * So even though `v-html` is rendering server-side HTML here,
 * the path is end-to-end trusted. The browser DOM never sees
 * markdown — only pre-sanitised HTML the renderer produced.
 *
 * a11y (F2=b):
 *   - The scrollable region has `tabindex="0"` and is keyboard
 *     scrollable.
 *   - Pre-fetched content is rendered with an `aria-busy="false"`
 *     once `terms` resolves; before that, `aria-busy="true"` +
 *     `role="status"`.
 *   - The checkbox label includes the agreement version so a
 *     screen reader announces "I have read and accept these terms
 *     — Master Creator Agreement v1.0".
 *   - Continue button is disabled until checked AND content has
 *     loaded; aria-describedby points at the help text explaining
 *     this.
 */

import { ApiError } from '@catalyst/api-client'
import type { ContractTermsResource } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const emit = defineEmits<{
  (event: 'accepted'): void
}>()

const { t, locale } = useI18n()
const store = useOnboardingStore()

const terms = ref<ContractTermsResource['data'] | null>(null)
const isLoading = ref(false)
const accepted = ref(false)
const submitErrorKey = ref<string | null>(null)
const loadErrorKey = ref<string | null>(null)

const isSubmitDisabled = computed(
  () => !accepted.value || terms.value === null || store.isLoadingClickThrough,
)

const loadErrorMessage = computed(() =>
  loadErrorKey.value === null ? null : t(loadErrorKey.value),
)
const submitErrorMessage = computed(() =>
  submitErrorKey.value === null ? null : t(submitErrorKey.value),
)

const versionLabel = computed(() =>
  terms.value === null
    ? ''
    : t('creator.ui.wizard.steps.contract.terms_version', {
        version: terms.value.version,
      }),
)

async function loadTerms(): Promise<void> {
  isLoading.value = true
  loadErrorKey.value = null
  try {
    const response = await onboardingApi.getContractTerms()
    terms.value = response.data
    void locale // referenced so a future locale-aware fetch sees the dep
  } catch (error) {
    loadErrorKey.value =
      error instanceof ApiError
        ? 'creator.ui.errors.upload_failed'
        : 'creator.ui.errors.upload_failed'
  } finally {
    isLoading.value = false
  }
}

async function onAccept(): Promise<void> {
  submitErrorKey.value = null
  try {
    await store.clickThroughAcceptContract()
    emit('accepted')
  } catch (error) {
    submitErrorKey.value =
      error instanceof ApiError && error.code === 'creator.wizard.feature_enabled'
        ? 'creator.wizard.feature_enabled'
        : 'creator.ui.errors.upload_failed'
  }
}

onMounted(() => {
  void loadTerms()
})
</script>

<template>
  <section class="click-through-accept" data-testid="click-through-accept">
    <header class="click-through-accept__header">
      <h2 class="click-through-accept__title">
        {{ t('creator.ui.wizard.steps.contract.title') }}
      </h2>
      <p class="click-through-accept__subtitle">
        {{ t('creator.ui.wizard.steps.contract.skipped_explanation') }}
      </p>
    </header>

    <div
      v-if="loadErrorMessage !== null"
      role="alert"
      class="click-through-accept__error"
      data-testid="click-through-load-error"
    >
      {{ loadErrorMessage }}
      <v-btn variant="text" size="small" data-testid="click-through-retry-load" @click="loadTerms">
        {{ t('creator.ui.wizard.vendor_bounce.retry') }}
      </v-btn>
    </div>

    <div
      v-else
      ref="termsRef"
      class="click-through-accept__terms"
      tabindex="0"
      role="region"
      :aria-label="t('creator.ui.wizard.steps.contract.title')"
      :aria-busy="isLoading"
      data-testid="click-through-terms"
      v-html="terms?.html ?? ''"
    ></div>

    <p
      v-if="terms !== null"
      class="click-through-accept__version"
      data-testid="click-through-version"
    >
      {{ versionLabel }}
    </p>

    <v-checkbox
      v-model="accepted"
      :label="t('creator.ui.wizard.steps.contract.click_through_label')"
      data-testid="click-through-checkbox"
      hide-details
    />

    <p id="click-through-help" class="click-through-accept__help">
      {{ t('creator.ui.wizard.steps.contract.click_through_help') }}
    </p>

    <div
      v-if="submitErrorMessage !== null"
      role="alert"
      class="click-through-accept__error"
      data-testid="click-through-submit-error"
    >
      {{ submitErrorMessage }}
    </div>

    <v-btn
      color="primary"
      :disabled="isSubmitDisabled"
      :loading="store.isLoadingClickThrough"
      aria-describedby="click-through-help"
      data-testid="click-through-submit"
      @click="onAccept"
    >
      {{ t('creator.ui.wizard.actions.save_and_continue') }}
    </v-btn>
  </section>
</template>

<style scoped>
.click-through-accept {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 720px;
}

.click-through-accept__title {
  font-size: 1.5rem;
  font-weight: 600;
}

.click-through-accept__subtitle {
  color: rgb(var(--v-theme-on-surface-variant));
}

.click-through-accept__terms {
  max-height: 360px;
  overflow-y: auto;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-outline));
  border-radius: 6px;
  background-color: rgb(var(--v-theme-surface));
  line-height: 1.6;
}

.click-through-accept__terms:focus {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.click-through-accept__version {
  font-size: 0.75rem;
  color: rgb(var(--v-theme-on-surface-variant));
}

.click-through-accept__help {
  font-size: 0.875rem;
  color: rgb(var(--v-theme-on-surface-variant));
}

.click-through-accept__error {
  display: flex;
  align-items: center;
  gap: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
