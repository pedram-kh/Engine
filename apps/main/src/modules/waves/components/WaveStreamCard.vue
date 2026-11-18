<script setup lang="ts">
/**
 * PL-1.3 — one stream card of the tracker (frame 01b): swatch and name, a tracking pill, one segment per step with
 * "9 of 13 steps", NEXT STEP, FINAL DELIVERY and the distinct owners. A delayed card has a red border and red
 * segments; a complete one reads "Complete".
 */
import type { WaveStreamSummary } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import { firstName, formatDue, shortDate } from '../trackerFormat'

const props = defineProps<{ summary: WaveStreamSummary; today: string }>()

const { t, locale } = useI18n()

const s = computed(() => props.summary)
const segments = computed(() => Array.from({ length: s.value.total }, (_, i) => i < s.value.done))

const pill = computed(() => {
  if (s.value.status === 'delayed') {
    return {
      tone: 'danger' as const,
      label: t(
        'app.waves.tracker.streamStatus.delayed',
        { days: s.value.days_delayed },
        s.value.days_delayed,
      ),
    }
  }
  if (s.value.status === 'complete') {
    return { tone: 'success' as const, label: t('app.waves.tracker.streamStatus.complete') }
  }
  return { tone: 'success' as const, label: t('app.waves.tracker.streamStatus.on_track') }
})

const nextDue = computed(() => {
  const n = s.value.next_step
  if (n === null || n.due_on === null) return null
  return formatDue(null, n.due_on, props.today, locale.value, {
    today: t('app.waves.tracker.due.today'),
    tomorrow: t('app.waves.tracker.due.tomorrow'),
  })
})

const owners = computed(() => {
  const named = s.value.owners.map((o) => ({ key: o.id, name: firstName(o.name) }))
  return s.value.has_client
    ? [...named, { key: 'client', name: t('app.waves.tracker.owner.client') }]
    : named
})
</script>

<template>
  <article
    class="stream-card"
    :class="{ 'stream-card--delayed': s.status === 'delayed' }"
    :data-test="`stream-card-${s.stream}`"
    :data-status="s.status"
  >
    <header class="stream-card__head">
      <span class="stream-card__swatch" :style="{ backgroundColor: s.colour }" aria-hidden="true" />
      <h3 class="stream-card__name">{{ t(`app.waves.tracker.streams.${s.stream}`) }}</h3>
      <AgencyStatusPill :tone="pill.tone" :label="pill.label" class="stream-card__pill" />
    </header>

    <div class="stream-card__figure">
      <span class="stream-card__bar" aria-hidden="true" data-test="stream-segments">
        <span
          v-for="(filled, n) in segments"
          :key="n"
          class="stream-card__segment"
          :class="{ 'stream-card__segment--on': filled }"
        />
      </span>
      <span class="stream-card__count" data-test="stream-count">
        {{ t('app.waves.tracker.card.steps', { done: s.done, total: s.total }) }}
      </span>
    </div>

    <dl class="stream-card__facts">
      <div>
        <dt>{{ t('app.waves.tracker.card.nextStep') }}</dt>
        <dd data-test="stream-next">
          <template v-if="s.next_step !== null">
            {{ s.next_step.name }}
            <span v-if="nextDue !== null" class="stream-card__muted"> · {{ nextDue }}</span>
          </template>
          <span v-else class="stream-card__muted">{{ t('app.waves.tracker.card.allDone') }}</span>
        </dd>
      </div>
      <div>
        <dt>{{ t('app.waves.tracker.card.finalDelivery') }}</dt>
        <dd data-test="stream-final">
          <template v-if="s.final_delivery !== null">
            {{ shortDate(s.final_delivery, today, locale) }}
          </template>
          <span v-else class="stream-card__muted">{{ t('app.waves.tracker.card.noDate') }}</span>
        </dd>
      </div>
    </dl>

    <ul v-if="owners.length > 0" class="stream-card__owners" data-test="stream-owners">
      <li v-for="o in owners" :key="o.key" class="stream-card__owner">
        <span class="stream-card__avatar" aria-hidden="true">{{ o.name.slice(0, 1) }}</span>
        {{ o.name }}
      </li>
    </ul>
  </article>
</template>

<style scoped>
.stream-card {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
  padding: 20px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 12px;
  background-color: rgb(var(--v-theme-surface));
}

.stream-card--delayed {
  border-color: rgb(var(--v-theme-error));
}

.stream-card__head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.stream-card__swatch {
  flex: none;
  width: 10px;
  height: 10px;
  border-radius: 3px;
}

.stream-card__name {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  line-height: 20px;
}

.stream-card__pill {
  margin-inline-start: auto;
}

.stream-card__figure {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.stream-card__bar {
  display: flex;
  gap: 3px;
}

.stream-card__segment {
  flex: 1 1 0;
  min-width: 0;
  height: 6px;
  border-radius: 3px;
  background-color: rgb(var(--v-theme-border-color));
}

.stream-card__segment--on {
  background-color: rgb(var(--v-theme-success));
}

.stream-card--delayed .stream-card__segment--on {
  background-color: rgb(var(--v-theme-error));
}

.stream-card__count {
  font-size: 12px;
  line-height: 16px;
  color: rgb(var(--v-theme-text-tertiary));
}

.stream-card__facts {
  display: grid;
  gap: 10px;
  margin: 0;
}

.stream-card__facts dt {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-text-tertiary));
}

.stream-card__facts dd {
  margin: 2px 0 0;
  font-size: 14px;
  line-height: 20px;
  overflow-wrap: anywhere;
}

.stream-card__muted {
  color: rgb(var(--v-theme-text-tertiary));
}

.stream-card__owners {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 13px;
}

.stream-card__owner {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.stream-card__avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  font-size: 11px;
  font-weight: 600;
  background-color: rgb(var(--v-theme-border-color));
}
</style>
