<script setup lang="ts">
/**
 * CountryDisplay — render an ISO 3166-1 alpha-2 country code as a
 * flag emoji + the localized country name passed in.
 *
 * Sprint 3 Chunk 3 sub-step 5 (Decision C1: display-shared, form-main).
 *
 * The component is i18n-free by design: the consumer page is
 * responsible for resolving the localized country name and
 * passing it in via `:label`. Keeping the package dependency-free
 * of `vue-i18n` is what lets both apps/main and apps/admin import
 * it without coupling.
 *
 * The flag emoji is generated from the ISO alpha-2 code via the
 * Unicode regional-indicator transform — a pure function of the
 * code, no asset bundling required. Invalid codes render the
 * emoji-less fallback (just the label).
 */

import { computed } from 'vue'

interface Props {
  /** ISO 3166-1 alpha-2 country code (e.g. "US", "IE"). Empty string allowed for "not set". */
  code: string | null
  /** Pre-localized country name (e.g. "United States"). */
  label: string
}

const props = defineProps<Props>()

const flagEmoji = computed(() => {
  const code = (props.code ?? '').trim().toUpperCase()
  if (!/^[A-Z]{2}$/.test(code)) return ''
  const base = 0x1f1e6 - 'A'.charCodeAt(0)
  const codePoints = [...code].map((ch) => base + ch.charCodeAt(0))
  return String.fromCodePoint(...codePoints)
})

const hasContent = computed(() => props.label.length > 0 || flagEmoji.value.length > 0)
</script>

<template>
  <span v-if="hasContent" class="country-display" data-testid="country-display">
    <span v-if="flagEmoji" aria-hidden="true" class="country-display__flag">{{ flagEmoji }}</span>
    <span class="country-display__label">{{ label }}</span>
  </span>
  <span v-else class="country-display country-display--empty" data-testid="country-display-empty"
    >—</span
  >
</template>

<style scoped>
.country-display {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.country-display__flag {
  font-size: 1.125rem;
  line-height: 1;
}

.country-display__label {
  font-size: 0.9375rem;
}

.country-display--empty {
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
