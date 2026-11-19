<script setup lang="ts">
/**
 * PL-2.1 — the six-step bar of the strategy workspace.
 *
 * Steps 1 and 2 are real links with a counts-only status line. Steps 3 and 4 ("Not available yet") and 5 and 6
 * (locked, with a lock) are plain elements: not links, not buttons, not in the tab order, `aria-disabled`, no
 * hover state, no pointer cursor. No tick is ever drawn: there is no "done" rule (S7). On a narrow screen the bar
 * scrolls sideways inside itself and step names never wrap.
 */
import type { StrategySummaryResource } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { STEPS, type StepKey } from '../constants'

const props = defineProps<{
  summary: StrategySummaryResource | null
  brandId: string
  waveId: string
  active: StepKey
}>()

const { t } = useI18n()

const statusLine = computed<Record<'usps' | 'personas', string>>(() => {
  const a = props.summary?.attributes
  if (!a) return { usps: '', personas: '' }
  const withPersonas = a.markets.filter((m) => m.persona_count > 0).length
  return {
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

function routeFor(key: StepKey): { name: string; params: { ulid: string; waveUlid: string } } {
  return {
    name: key === 'usps' ? 'strategy.usps' : 'strategy.personas',
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
          <span class="strategy-step__circle">
            <v-icon
              v-if="step.state === 'locked'"
              icon="mdi-lock-outline"
              size="14"
              aria-hidden="true"
            />
            <template v-else>{{ step.n }}</template>
          </span>
          <span class="strategy-step__text">
            <span class="strategy-step__name">{{ t(`app.strategy.stepper.${step.key}`) }}</span>
            <span class="strategy-step__status" :data-test="`strategy-step-${step.key}-status`">
              {{
                step.state === 'locked'
                  ? t('app.strategy.stepper.locked')
                  : t('app.strategy.stepper.notAvailable')
              }}
            </span>
          </span>
        </div>
      </li>
    </ol>
  </nav>
</template>

<style scoped>
.strategy-stepper {
  overflow-x: auto;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
}
.strategy-stepper__list {
  display: flex;
  list-style: none;
  margin: 0;
  padding: 0;
  min-width: max-content;
}
.strategy-stepper__item {
  flex: 1 0 auto;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.strategy-stepper__item:last-child {
  border-right: 0;
}
.strategy-step {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  white-space: nowrap;
  color: inherit;
  text-decoration: none;
}
.strategy-step__circle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 600;
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.strategy-step__text {
  display: flex;
  flex-direction: column;
}
.strategy-step__name {
  font-size: 14px;
  font-weight: 600;
}
.strategy-step__status {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.7);
  min-height: 1.2em;
}
.strategy-step--live:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
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
    padding: 12px;
    gap: 8px;
  }
  .strategy-step__circle {
    flex: none;
  }
}
.strategy-step--off {
  cursor: default;
  opacity: 0.6;
  user-select: none;
}
</style>
