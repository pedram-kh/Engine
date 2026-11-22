<script setup lang="ts">
/**
 * PL-2.1 — the six-step bar of the strategy workspace.
 *
 * Steps 1 to 4 are real links with a counts-only status line (step 4: "{approved} of {total} approved"). Steps 5 and 6
 * are plain elements: not links, not buttons, not in the tab order, `aria-disabled`, no hover state, no pointer
 * cursor, NO lock icon: they say "Not available yet" and, while any market has concepts that are not all approved,
 * carry the warning with the counts (PL-2.3, C6: a message, never a block). PL-2.5: every live step carries its REAL state from the server: not started (an empty circle with the number), in progress (an amber circle with the number) or done (a green circle with a check); a per-market step in progress says "n of m markets". Creators and Scripts stay "Not available yet". On a narrow screen the bar
 * scrolls sideways inside itself and step names never wrap.
 */
import type { StrategySummaryResource } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { stepFourLine, stepWarning } from '../conceptsLogic'
import { stateFor, type StepName } from '../completionLogic'
import { STEPS, type StepKey } from '../constants'

const props = defineProps<{
  summary: StrategySummaryResource | null
  brandId: string
  waveId: string
  active: StepKey
}>()

const { t } = useI18n()

type LiveKey = StepName

function stateOf(key: StepKey): 'not_started' | 'in_progress' | 'done' {
  const a = props.summary?.attributes
  if (!a || key === 'creators' || key === 'scripts') return 'not_started'
  return stateFor(a, key, null)
}

const baseLine = computed<Record<LiveKey, string>>(() => {
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

/**
 * The line under a step's name (PL-2.5 walk, N4): the counts the step always showed, then the market progress while a
 * per-market step is in progress ("12 of 24 cells set · 1 of 2 markets"); done keeps the check and says "Complete" and
 * what it shows ("Complete · 3 personas"); not started keeps the empty-step wording. USPs is per wave and keeps its count.
 */
const statusLine = computed<Record<LiveKey, string>>(() => {
  const a = props.summary?.attributes
  const out = { ...baseLine.value }
  if (!a) return out
  const progress = (key: 'personas' | 'matrix' | 'concepts'): string =>
    t(
      'app.strategy.stepper.marketsOf',
      { done: a.steps[key].markets_done, total: a.steps[key].markets_total },
      a.steps[key].markets_total,
    )
  const joined = (...parts: string[]): string => parts.join(' · ')
  for (const key of ['usps', 'personas', 'matrix', 'concepts'] as const) {
    const state = a.steps[key].state
    if (state === 'done') {
      const counts =
        key === 'personas'
          ? t('app.strategy.stepper.personasCount', { count: a.persona_count }, a.persona_count)
          : baseLine.value[key]
      out[key] = joined(t('app.strategy.completion.complete'), counts)
    } else if (state === 'in_progress' && key !== 'usps') {
      out[key] =
        key === 'personas'
          ? t(
              'app.strategy.stepper.statusPersonas',
              {
                count: a.persona_count,
                covered: a.steps.personas.markets_done,
                markets: a.steps.personas.markets_total,
              },
              a.persona_count,
            )
          : joined(baseLine.value[key], progress(key))
    }
  }
  return out
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
          :data-state="stateOf(step.key)"
        >
          <span
            class="strategy-step__circle"
            :class="`strategy-step__circle--${stateOf(step.key)}`"
            :data-test="`strategy-step-${step.key}-circle`"
          >
            <v-icon
              v-if="stateOf(step.key) === 'done'"
              icon="mdi-check"
              size="14"
              aria-hidden="true"
            />
            <template v-else>{{ step.n }}</template>
          </span>
          <span class="strategy-step__text">
            <span class="strategy-step__name">{{ t(`app.strategy.stepper.${step.key}`) }}</span>
            <span
              class="strategy-step__status"
              :title="statusLine[step.key as LiveKey]"
              :data-test="`strategy-step-${step.key}-status`"
            >
              {{ statusLine[step.key as LiveKey] }}
            </span>
            <span class="sr-only">{{ t(`app.strategy.stepper.state.${stateOf(step.key)}`) }}</span>
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
 * others); status 11.5px (green when done, amber in progress).
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
  position: relative;
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
  background: transparent;
  border: 1.5px solid rgb(var(--v-theme-border-color));
}
/* PL-2.5: in progress is amber with its number, done is green with a check (frame 13:547). */
.strategy-step__circle--in_progress {
  background: rgb(var(--v-theme-warning));
  border-color: rgb(var(--v-theme-warning));
  color: rgb(var(--v-theme-on-warning, 11 11 12));
}
.strategy-step__circle--done {
  background: rgb(var(--v-theme-primary));
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
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
  font-size: 11.5px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
  min-height: 1.2em;
  /* Narrow: one line that ends in an ellipsis (the full text is the tooltip). */
  max-width: 230px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
/* Status text takes the state's colour (frame 13:4538, 13:4558): green when done, amber while in progress. */
.strategy-step[data-state='done'] .strategy-step__status {
  color: rgb(var(--v-theme-success));
}
.strategy-step[data-state='in_progress'] .strategy-step__status {
  color: rgb(var(--v-theme-warning));
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
/* Wide enough for six steps: share the width and let the status wrap, so no step is clipped. Narrower, the bar
   keeps its natural width and scrolls inside itself. */
@media (min-width: 1100px) {
  .strategy-stepper__list {
    min-width: 0;
  }
  /* The four live steps carry the longer lines; steps 5 and 6 only say "Not available yet". */
  .strategy-stepper__item {
    flex: 1.5 1 0;
    min-width: 0;
  }
  .strategy-stepper__item:nth-child(n + 5) {
    flex: 1 1 0;
  }
  .strategy-step {
    white-space: normal;
  }
  .strategy-step__text {
    min-width: 0;
  }
  .strategy-step__status {
    max-width: none;
  }
}
.strategy-step--off {
  cursor: default;
  opacity: 0.6;
  user-select: none;
}
</style>
