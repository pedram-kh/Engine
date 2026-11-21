<script setup lang="ts">
/**
 * PL-2.1 — the six-step bar of the strategy workspace.
 *
 * Steps 1 to 4 are real links with a counts-only status line (step 4: "{approved} of {total} approved"). Steps 5 and 6
 * are plain elements: not links, not buttons, not in the tab order, `aria-disabled`, no hover state, no pointer
 * cursor, NO lock icon: they say "Not available yet" and, while any market has concepts that are not all approved,
 * carry the warning with the counts (PL-2.3, C6: a message, never a block). No tick is ever drawn: there is no "done" rule (S7). On a narrow screen the bar
 * scrolls sideways inside itself and step names never wrap.
 */
import type { StrategySummaryResource } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { stepFourLine, stepWarning } from '../conceptsLogic'
import { STEPS, type StepKey } from '../constants'

const props = defineProps<{
  summary: StrategySummaryResource | null
  brandId: string
  waveId: string
  active: StepKey
}>()

const { t } = useI18n()

const statusLine = computed<Record<'usps' | 'personas' | 'matrix' | 'concepts', string>>(() => {
  const a = props.summary?.attributes
  if (!a) return { usps: '', personas: '', matrix: '', concepts: '' }
  const four = stepFourLine(a.concepts)
  const withPersonas = a.markets.filter((m) => m.persona_count > 0).length
  const m = a.matrix
  return {
    concepts:
      four.kind === 'empty'
        ? t('app.strategy.stepper.conceptsNone')
        : t('app.strategy.stepper.conceptsStatus', { approved: four.approved, total: four.total }),
    matrix:
      m.cells_total === 0
        ? t('app.strategy.stepper.matrixNone')
        : t(
            'app.strategy.stepper.matrixStatus',
            { set: m.cells_set, total: m.cells_total },
            m.cells_total,
          ),
    usps:
      a.usp_count === 0
        ? t('app.strategy.stepper.uspNone')
        : t('app.strategy.stepper.statusUsps', { count: a.usp_count }, a.usp_count),
    personas:
      a.persona_count === 0
        ? t('app.strategy.stepper.personaNone')
        : t(
            'app.strategy.stepper.statusPersonas',
            { count: a.persona_count, covered: withPersonas, markets: a.markets.length },
            a.persona_count,
          ),
  }
})

/** The warning steps 5 and 6 carry: the wave's counts while any market is not complete, else nothing. */
const warning = computed(() => stepWarning(props.summary?.attributes.concepts))
const warningText = computed(() => {
  const w = warning.value
  if (w.kind === 'counts')
    return t('app.strategy.stepper.conceptsWarning', { approved: w.approved, total: w.total })
  return w.kind === 'empty' ? t('app.strategy.stepper.conceptsWarningNone') : ''
})

function routeFor(key: StepKey): { name: string; params: { ulid: string; waveUlid: string } } {
  return {
    name:
      key === 'usps'
        ? 'strategy.usps'
        : key === 'matrix'
          ? 'strategy.matrix'
          : key === 'concepts'
            ? 'strategy.concepts'
            : 'strategy.personas',
    params: { ulid: props.brandId, waveUlid: props.waveId },
  }
}
</script>

<template>
  <nav
    class="strategy-stepper"
    :aria-label="t('app.strategy.stepper.label')"
    data-test="strategy-stepper"
  >
    <ol class="strategy-stepper__list">
      <li v-for="step in STEPS" :key="step.key" class="strategy-stepper__item">
        <router-link
          v-if="step.state === 'live'"
          :to="routeFor(step.key)"
          class="strategy-step strategy-step--live"
          :class="{ 'strategy-step--active': active === step.key }"
          :aria-current="active === step.key ? 'step' : undefined"
          :data-test="`strategy-step-${step.key}`"
        >
          <span class="strategy-step__circle">{{ step.n }}</span>
          <span class="strategy-step__text">
            <span class="strategy-step__name">{{ t(`app.strategy.stepper.${step.key}`) }}</span>
            <span class="strategy-step__status" :data-test="`strategy-step-${step.key}-status`">
              {{ statusLine[step.key] }}
            </span>
          </span>
        </router-link>
        <div
          v-else
          class="strategy-step strategy-step--off"
          aria-disabled="true"
          :data-test="`strategy-step-${step.key}`"
        >
          <span class="strategy-step__circle">{{ step.n }}</span>
          <span class="strategy-step__text">
            <span class="strategy-step__name">{{ t(`app.strategy.stepper.${step.key}`) }}</span>
            <span class="strategy-step__status" :data-test="`strategy-step-${step.key}-status`">
              {{ t('app.strategy.stepper.notAvailable') }}
            </span>
            <span
              v-if="warningText"
              class="strategy-step__warn"
              :data-test="`strategy-step-${step.key}-warning`"
            >
              <v-icon icon="mdi-alert-outline" size="13" aria-hidden="true" />
              {{ warningText }}
            </span>
          </span>
        </div>
      </li>
    </ol>
  </nav>
</template>

<style scoped>
/*
 * Values from frame 13:828 / 13:428 (stepper): a bar of surface fill, 1px border, 10px radius, 6px padding and
 * 6px gaps; steps of 10px 14px with a 12px gap and 8px radius; a 28px circle; the current step on the raised fill
 * with a 60% primary border; names 14px (600 and on-surface for the current step, 500 and on-surface-variant for the
 * others); status 12px.
 */
.strategy-stepper {
  overflow-x: auto;
  padding: 6px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
}
.strategy-stepper__list {
  display: flex;
  gap: 6px;
  list-style: none;
  margin: 0;
  padding: 0;
  min-width: max-content;
}
.strategy-stepper__item {
  flex: 1 0 auto;
  display: flex;
}
.strategy-step {
  flex: 1 1 auto;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  border: 1px solid transparent;
  border-radius: 8px;
  white-space: nowrap;
  color: inherit;
  text-decoration: none;
}
.strategy-step__circle {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
  background: rgb(var(--v-theme-surface-bright));
}
.strategy-step__text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.strategy-step__name {
  font-size: 14px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.strategy-step__status {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
  min-height: 1.2em;
}
.strategy-step__warn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-warning));
}
.strategy-step--live:hover {
  background: rgb(var(--v-theme-surface-bright));
}
.strategy-step--active {
  border-color: color-mix(in srgb, rgb(var(--v-theme-primary)) 60%, transparent);
  background: rgb(var(--v-theme-surface-bright));
}
.strategy-step--active .strategy-step__name {
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}
.strategy-step--active .strategy-step__circle {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
/* Wide enough for six steps: share the width and let the status wrap, so no step is clipped. Narrower, the bar
   keeps its natural width and scrolls inside itself. */
@media (min-width: 1100px) {
  .strategy-stepper__list {
    min-width: 0;
  }
  .strategy-stepper__item {
    flex: 1 1 0;
    min-width: 0;
  }
  .strategy-step {
    white-space: normal;
  }
}
.strategy-step--off {
  cursor: default;
  opacity: 0.6;
  user-select: none;
}
</style>
