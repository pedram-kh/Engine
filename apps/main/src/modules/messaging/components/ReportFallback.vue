<script setup lang="ts">
/**
 * AH-112 — the always-visible fallback beside the "Report a problem" mailto.
 *
 * A mailto: does nothing on a phone with no mail account, and the Capacitor view gives no signal
 * that it failed. So the address and the reference are ALWAYS shown as selectable text, with a
 * copy button. The mailto link is unchanged and still rendered by the parent.
 */

import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { SUPPORT_EMAIL } from '@/core/config/support'

import { reportCopyText, reportReference } from '../utils/reportProblem'

const props = defineProps<{
  /** The same subject the mailto carries; its last word is the reference. */
  subject: string
}>()

const { t } = useI18n()

const reference = computed(() => reportReference(props.subject))
const line = computed(() =>
  t('app.messaging.reportFallback', { email: SUPPORT_EMAIL, reference: reference.value }),
)

const copied = ref(false)
let resetTimer: ReturnType<typeof setTimeout> | null = null

/** Last resort where the async clipboard API is missing or refuses (older WebViews). */
function legacyCopy(text: string): boolean {
  const field = document.createElement('textarea')
  field.value = text
  field.setAttribute('readonly', '')
  field.style.position = 'fixed'
  field.style.opacity = '0'
  document.body.appendChild(field)
  field.select()
  let ok = false
  try {
    ok = document.execCommand('copy')
  } catch {
    ok = false
  }
  document.body.removeChild(field)
  return ok
}

async function copy(): Promise<void> {
  const text = reportCopyText(reference.value)
  let ok = false
  if (typeof navigator !== 'undefined' && navigator.clipboard !== undefined) {
    try {
      await navigator.clipboard.writeText(text)
      ok = true
    } catch {
      ok = false
    }
  }
  if (!ok) {
    ok = legacyCopy(text)
  }
  // A failed copy shows nothing: the text above stays selectable by hand.
  copied.value = ok
  if (resetTimer !== null) clearTimeout(resetTimer)
  resetTimer = setTimeout(() => {
    copied.value = false
  }, 2000)
}

onBeforeUnmount(() => {
  if (resetTimer !== null) clearTimeout(resetTimer)
})
</script>

<template>
  <div class="report-fallback text-caption" data-test="report-fallback">
    <span class="report-fallback__text" data-test="report-fallback-text">{{ line }}</span>
    <v-btn
      :icon="copied ? 'mdi-check' : 'mdi-content-copy'"
      variant="text"
      size="x-small"
      density="comfortable"
      :aria-label="line"
      data-test="report-fallback-copy"
      @click="copy"
    />
  </div>
</template>

<style scoped>
.report-fallback {
  display: flex;
  align-items: center;
  gap: 4px;
  color: rgba(var(--v-theme-on-surface), 0.7);
}

/* The text must be selectable on iOS even where an ancestor turns selection off. */
.report-fallback__text {
  -webkit-user-select: text;
  user-select: text;
  -webkit-touch-callout: default;
  overflow-wrap: anywhere;
}
</style>
