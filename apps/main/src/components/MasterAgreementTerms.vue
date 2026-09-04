<script setup lang="ts">
/**
 * MasterAgreementTerms — the ONE rendering path for the master agreement's
 * full text, shared by two hosts (AH-098):
 *
 *   1. The wizard's Step 8 click-through fallback
 *      ({@link ../modules/onboarding/components/ClickThroughAccept.vue}),
 *      which wraps this with a scroll-gated checkbox + submit button.
 *   2. The creator profile's "Master agreement" panel
 *      ({@link ../modules/creators/components/CreatorMasterAgreementSection.vue}),
 *      which mounts this read-only, no gate, no accept action.
 *
 * Extracted from ClickThroughAccept.vue's pre-AH-098 fetch+render block so
 * neither host hand-copies the `v-html` region, its sanitisation-trust
 * docblock, or the scroll-to-end measurement — "one rendering path, two
 * hosts, never a copy" (Pedram's locked decision).
 *
 * Sources from the SAME endpoint both hosts always used —
 * `GET /api/v1/creators/me/wizard/contract/terms` — which is stateless
 * (no wizard-step gate, no `contract_signing_enabled` check; see
 * `CreatorWizardController::getContractTerms()`) and always renders
 * `ContractTermsRenderer::CURRENT_VERSION`'s text. There is no versioned
 * retrieval today — a creator who accepted an older version sees the
 * CURRENT text on both re-reads (wizard step re-entry and this profile
 * panel), never a historical snapshot. Honest limitation, not silently
 * hidden: `contracts.body_markdown` (the accept-time snapshot) exists in
 * the backend row but is not exposed by any creator-facing endpoint —
 * surfacing it would be a new API resource, out of this batch's scope
 * (see AH-098 in docs/reviews/adhoc-changes-log.md).
 *
 * Trust boundary (unchanged from the pre-extraction docblock): the HTML
 * body comes from the server-rendered terms endpoint, which reads a
 * static markdown file under the platform's control (not user input) and
 * passes it through league/commonmark with `allow_unsafe_links: false` +
 * `html_input: 'escape'`. `v-html` here renders pre-sanitised, trusted
 * server HTML — the browser DOM never sees raw markdown.
 *
 * Scroll-to-end tracking lives here (not in each host) because it is
 * itself generic "did the reader reach the bottom of this box" logic, not
 * wizard-specific. Hosts that don't care (the profile panel) simply don't
 * listen for `reached-end`.
 */

import { ApiError } from '@catalyst/api-client'
import type { ContractTermsResource } from '@catalyst/api-client'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { onboardingApi } from '../modules/onboarding/api/onboarding.api'

const props = defineProps<{
  /** Accessible name for the scrollable terms region (host-supplied — the
   * wizard step and the profile panel each have their own heading text).
   * Named `label`, not `ariaLabel`, purely so template usage stays a plain
   * single-word prop binding — no kebab/camel ambiguity to trip over. */
  label: string
}>()

const emit = defineEmits<{
  (event: 'reachedEnd', value: boolean): void
}>()

const { t } = useI18n()

const terms = ref<ContractTermsResource['data'] | null>(null)
const isLoading = ref(false)
const loadErrorKey = ref<string | null>(null)

const termsRef = ref<HTMLElement | null>(null)

// px tolerance so sub-pixel layout / browser-zoom rounding still registers as
// "scrolled to the end" rather than leaving the gate one pixel short.
const SCROLL_END_THRESHOLD = 4

function evaluateScrollState(): void {
  const el = termsRef.value
  if (el === null) {
    return
  }
  const overflow = el.scrollHeight - el.clientHeight
  if (overflow <= SCROLL_END_THRESHOLD) {
    emit('reachedEnd', true)
    return
  }
  emit('reachedEnd', el.scrollTop + el.clientHeight >= el.scrollHeight - SCROLL_END_THRESHOLD)
}

const versionLabel = computed(() =>
  terms.value === null
    ? ''
    : t('creator.ui.wizard.steps.contract.terms_version', {
        version: terms.value.version,
      }),
)

const loadErrorMessage = computed(() =>
  loadErrorKey.value === null ? null : t(loadErrorKey.value),
)

async function load(): Promise<void> {
  isLoading.value = true
  loadErrorKey.value = null
  try {
    const response = await onboardingApi.getContractTerms()
    terms.value = response.data
    await nextTick()
    evaluateScrollState()
  } catch (error) {
    loadErrorKey.value =
      error instanceof ApiError
        ? 'creator.ui.errors.upload_failed'
        : 'creator.ui.errors.upload_failed'
  } finally {
    isLoading.value = false
  }
}

defineExpose({ retry: load })

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="master-agreement-terms">
    <div
      v-if="loadErrorMessage !== null"
      role="alert"
      class="master-agreement-terms__error"
      data-testid="master-agreement-load-error"
    >
      {{ loadErrorMessage }}
      <v-btn variant="text" size="small" data-testid="master-agreement-retry-load" @click="load">
        {{ t('creator.ui.wizard.vendor_bounce.retry') }}
      </v-btn>
    </div>

    <div
      v-else
      ref="termsRef"
      class="master-agreement-terms__scroll"
      tabindex="0"
      role="region"
      :aria-label="props.label"
      :aria-busy="isLoading"
      data-testid="master-agreement-terms"
      @scroll="evaluateScrollState"
      v-html="terms?.html ?? ''"
    ></div>

    <p
      v-if="terms !== null"
      class="master-agreement-terms__version"
      data-testid="master-agreement-version"
    >
      {{ versionLabel }}
    </p>
  </div>
</template>

<style scoped>
.master-agreement-terms {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* Height-bounded with internal scroll — the host page must never grow by
   the document's length (locked decision, both hosts). */
.master-agreement-terms__scroll {
  max-height: 360px;
  overflow-y: auto;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-outline));
  border-radius: 6px;
  background-color: rgb(var(--v-theme-surface));
  line-height: 1.6;
}

.master-agreement-terms__scroll:focus {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.master-agreement-terms__version {
  font-size: 0.75rem;
  color: rgb(var(--v-theme-on-surface-variant));
}

.master-agreement-terms__error {
  display: flex;
  align-items: center;
  gap: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
