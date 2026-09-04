<script setup lang="ts">
/**
 * Click-through acceptance fallback for the master agreement
 * (Sprint 3 Chunk 3 sub-step 4, Decision E2=a — inline scrollable).
 *
 * The agreement text itself renders via {@link MasterAgreementTerms}
 * (extracted AH-098 — "one rendering path, two hosts, never a copy"; the
 * creator profile's "Master agreement" panel is the second host). This
 * component keeps only what's specific to the WIZARD's acceptance flow:
 * the subtitle, the scroll-gated checkbox, and the accept button.
 *
 * a11y (F2=b):
 *   - The checkbox label includes the agreement version so a
 *     screen reader announces "I have read and accept these terms
 *     — Master Creator Agreement v1.1".
 *   - Continue button is disabled until checked AND content has
 *     loaded; aria-describedby points at the help text explaining
 *     this.
 */

import { ApiError } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import MasterAgreementTerms from '@/components/MasterAgreementTerms.vue'

import { useOnboardingStore } from '../stores/useOnboardingStore'

const emit = defineEmits<{
  (event: 'accepted'): void
}>()

const { t } = useI18n()
const store = useOnboardingStore()

const accepted = ref(false)
const submitErrorKey = ref<string | null>(null)
const reachedEnd = ref(false)

function onReachedEnd(value: boolean): void {
  reachedEnd.value = value
}

const helpTextKey = computed(() =>
  reachedEnd.value
    ? 'creator.ui.wizard.steps.contract.click_through_help'
    : 'creator.ui.wizard.steps.contract.click_through_scroll_hint',
)

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
</script>

<template>
  <section class="click-through-accept" data-testid="click-through-accept">
    <p class="click-through-accept__subtitle">
      {{ t('creator.ui.wizard.steps.contract.skipped_explanation') }}
    </p>

    <MasterAgreementTerms
      :label="t('creator.ui.wizard.steps.contract.title')"
      @reached-end="onReachedEnd"
    />

    <v-checkbox
      v-model="accepted"
      :disabled="!reachedEnd"
      :label="t('creator.ui.wizard.steps.contract.click_through_label')"
      data-testid="click-through-checkbox"
      hide-details
    />

    <p id="click-through-help" class="click-through-accept__help">
      {{ t(helpTextKey) }}
    </p>

    <div
      v-if="submitErrorKey !== null"
      role="alert"
      class="click-through-accept__error"
      data-testid="click-through-submit-error"
    >
      {{ t(submitErrorKey) }}
    </div>

    <v-btn
      color="primary"
      :disabled="!accepted || store.isLoadingClickThrough"
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

.click-through-accept__subtitle {
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
