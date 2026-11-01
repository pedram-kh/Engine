<script setup lang="ts">
/**
 * AH-114 (S2.1) — the agency status pill: a dot and a word on a 15 % tint (Figma
 * "Status pill", rule R9). One component, six tones; the tone and the word both come
 * from `AGENCY_STATUS_TONES`, so no page picks a colour by hand.
 *
 * Two ways to use it:
 *   - `<AgencyStatusPill kind="campaign" :status="row.status" />` looks the value up
 *     in the table (tone and an existing i18n key).
 *   - `<AgencyStatusPill tone="success" label="..." />` for a one-off, with the
 *     caller's own already-translated word.
 *
 * It never renders without a word. `view` decides: a table entry with no tone (a status
 * that shows no pill) renders nothing; a value the table does not know yet (a backend
 * ahead of the table) renders a neutral pill with the raw value; an empty or blank
 * label renders nothing.
 *
 * Colour is read from the agency theme (`--v-theme-*`), so both themes work without a
 * class per theme. The dot is the status fill; the word is the `*-text` colour (the
 * fill alone fails AA as text on its own tint, plan §2.1 and §2.1b).
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  agencyStatusEntry,
  type AgencyStatusKind,
  type AgencyStatusTone,
} from '../status/agencyStatusTones'

const props = defineProps<{
  /** Which status union `status` belongs to (table mode). */
  kind?: AgencyStatusKind
  /** The status value (table mode). */
  status?: string | null
  /** Explicit tone (one-off mode). */
  tone?: AgencyStatusTone
  /** Explicit, already-translated word (one-off mode). */
  label?: string
}>()

const { t, te } = useI18n()

interface PillView {
  tone: AgencyStatusTone
  label: string
}

const view = computed<PillView | null>(() => {
  if (props.tone !== undefined) {
    const label = (props.label ?? '').trim()
    return label === '' ? null : { tone: props.tone, label }
  }

  if (props.kind === undefined || props.status === undefined || props.status === null) {
    return null
  }

  const entry = agencyStatusEntry(props.kind, props.status)
  if (entry === undefined) {
    // A value the table does not know yet: a neutral pill with the raw word, never a
    // bare coloured chip and never an empty one.
    return props.status === '' ? null : { tone: 'neutral', label: props.status }
  }
  if (entry.tone === null || entry.labelKey === null) {
    return null
  }
  return {
    tone: entry.tone,
    label: te(entry.labelKey) ? t(entry.labelKey) : props.status,
  }
})
</script>

<template>
  <span
    v-if="view !== null"
    class="agency-status-pill"
    :class="`agency-status-pill--${view.tone}`"
    :data-tone="view.tone"
    data-test="agency-status-pill"
  >
    <span class="agency-status-pill__dot" aria-hidden="true" />
    <span class="agency-status-pill__label">{{ view.label }}</span>
  </span>
</template>

<style scoped>
.agency-status-pill {
  --pill-fill: var(--v-theme-status-neutral);
  --pill-text: var(--v-theme-neutral-text);

  position: relative;
  display: inline-flex;
  align-items: center;
  gap: var(--space-2, 8px);
  padding: 4px 10px;
  border-radius: 999px;
  white-space: nowrap;
  vertical-align: middle;
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  letter-spacing: 0;
  color: rgb(var(--pill-text));
  isolation: isolate;
}

/* The 15 % tint is its own layer so the word keeps full colour. */
.agency-status-pill::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: -1;
  border-radius: inherit;
  background-color: rgb(var(--pill-fill));
  opacity: 0.15;
}

.agency-status-pill__dot {
  flex: none;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background-color: rgb(var(--pill-fill));
}

.agency-status-pill--success {
  --pill-fill: var(--v-theme-success);
  --pill-text: var(--v-theme-success-text);
}

.agency-status-pill--info {
  --pill-fill: var(--v-theme-info);
  --pill-text: var(--v-theme-info-text);
}

.agency-status-pill--warning {
  --pill-fill: var(--v-theme-warning);
  --pill-text: var(--v-theme-warning-text);
}

.agency-status-pill--danger {
  --pill-fill: var(--v-theme-error);
  --pill-text: var(--v-theme-error-text);
}

.agency-status-pill--neutral {
  --pill-fill: var(--v-theme-status-neutral);
  --pill-text: var(--v-theme-neutral-text);
}

.agency-status-pill--ai {
  --pill-fill: var(--v-theme-status-ai);
  --pill-text: var(--v-theme-ai-text);
}
</style>
