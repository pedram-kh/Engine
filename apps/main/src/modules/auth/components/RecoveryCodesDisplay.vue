<script setup lang="ts">
/**
 * RecoveryCodesDisplay — renders the plaintext recovery codes returned
 * by `verifyTotp()` / `regenerateRecoveryCodes()`.
 *
 * Pre-answered chunk-6.7 Q1 and chunk-6 plan rule (PROJECT-WORKFLOW.md
 * § 5.1, enforced by `tests/unit/architecture/no-recovery-codes-in-store.spec.ts`):
 *
 *   The codes MUST be passed in via the `codes` prop (component-local
 *   state in the parent page) and MUST NEVER be stored on the auth
 *   store. The chunk-6.7 source-inspection extension also forbids
 *   importing `useAuthStore` in this file at all — the architecture
 *   test enforces it.
 *
 * Behaviour:
 *   - Displays the codes in a monospace block with copy + download
 *     actions.
 *   - The "I have saved them" confirmation button stays disabled for
 *     `COUNTDOWN_SECONDS` after mount, with the remaining seconds
 *     announced via `aria-live="polite"` so screen readers track the
 *     countdown.
 *   - Emits `confirmed` when the user clicks the now-enabled button.
 *
 * The countdown is enforced unconditionally in code: there is no
 * "skip" prop / dev-mode bypass / debug query. Chunk-6.7 review
 * priority #5 calls this out.
 */

import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

export interface Props {
  codes: ReadonlyArray<string>
  /**
   * Override of the countdown duration. Tests inject a smaller number
   * to keep the suite fast. Production callers always use the default.
   */
  countdownSeconds?: number
}

const props = withDefaults(defineProps<Props>(), {
  countdownSeconds: 5,
})

const emit = defineEmits<{ confirmed: [] }>()

const { t } = useI18n()

const remaining = ref(props.countdownSeconds)
let interval: ReturnType<typeof setInterval> | null = null

const codesText = computed(() => props.codes.join('\n'))
const canConfirm = computed(() => remaining.value <= 0)

const announcement = computed(() =>
  remaining.value > 0
    ? t('auth.ui.descriptions.recovery_codes_countdown', { seconds: remaining.value })
    : t('auth.ui.descriptions.recovery_codes_countdown_done'),
)

function tick(): void {
  if (remaining.value > 0) {
    remaining.value -= 1
  }
  if (remaining.value <= 0 && interval !== null) {
    clearInterval(interval)
    interval = null
  }
}

async function copyCodes(): Promise<void> {
  if (typeof navigator === 'undefined' || navigator.clipboard === undefined) {
    return
  }
  await navigator.clipboard.writeText(codesText.value)
}

function downloadCodes(): void {
  const blob = new Blob([codesText.value], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'catalyst-recovery-codes.txt'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

function confirm(): void {
  /* c8 ignore start -- defensive: the v-btn `:disabled="!canConfirm"`
     binding stops the click in production. The handler is only
     invoked once `canConfirm` flips true. The guard exists so a
     future template change that drops the disabled binding cannot
     bypass the countdown. */
  if (!canConfirm.value) {
    return
  }
  /* c8 ignore stop */
  emit('confirmed')
}

onMounted(() => {
  interval = setInterval(tick, 1000)
})

onBeforeUnmount(() => {
  if (interval !== null) {
    clearInterval(interval)
    interval = null
  }
})
</script>

<template>
  <section data-test="recovery-codes-display">
    <h3 class="text-h6 mb-2" data-test="recovery-codes-heading">
      {{ t('auth.ui.headings.recovery_codes') }}
    </h3>

    <p class="text-body-2 mb-2" data-test="recovery-codes-warning">
      {{ t('auth.ui.descriptions.recovery_codes_warning') }}
    </p>

    <pre class="recovery-codes__list pa-3" data-test="recovery-codes-list">{{ codesText }}</pre>

    <div class="d-flex ga-2 mb-3">
      <v-btn variant="outlined" data-test="recovery-codes-copy" @click="copyCodes">
        {{ t('auth.ui.actions.copy_codes') }}
      </v-btn>
      <v-btn variant="outlined" data-test="recovery-codes-download" @click="downloadCodes">
        {{ t('auth.ui.actions.download_codes') }}
      </v-btn>
    </div>

    <div
      role="status"
      aria-live="polite"
      class="text-body-2 text-medium-emphasis mb-2"
      data-test="recovery-codes-countdown"
    >
      {{ announcement }}
    </div>

    <v-btn
      color="primary"
      block
      :disabled="!canConfirm"
      data-test="recovery-codes-confirm"
      @click="confirm"
    >
      {{ t('auth.ui.actions.i_have_saved_them') }}
    </v-btn>
  </section>
</template>

<style scoped>
.recovery-codes__list {
  font-family: var(--v-font-family-monospace, monospace);
  background-color: rgb(var(--v-theme-surface-variant, 240, 240, 240));
  white-space: pre;
  overflow-x: auto;
}
</style>
