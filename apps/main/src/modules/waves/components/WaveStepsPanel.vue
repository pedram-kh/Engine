<script setup lang="ts">
/**
 * PL-1.3 — the "Deliverable steps" panel (frame 01b): filter chips, one group per stream, completed steps folded
 * into one line, open steps with a round check, owner, due date, status pill and a row menu, and a footer with
 * Add step and Add stream.
 *
 * Role gates here are the UI half of the server's: staff can toggle, change owner and dates; only admin and
 * manager (`canManage`) see Add step, Remove, Remove stream and Add stream. A strategy step has no Remove.
 * No design frame covers the narrow layout (plan 4.3): below 768 px a row stacks.
 */
import type {
  WaveStepResource,
  WaveStream,
  WaveStreamSummary,
  WaveTrackerResource,
} from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import {
  completedSummary,
  firstName,
  formatDue,
  groupSteps,
  shortDate,
  STREAM_ORDER,
  type TrackerFilter,
} from '../trackerFormat'

const props = defineProps<{
  tracker: WaveTrackerResource
  canManage: boolean
  meUlid: string | null
  /** Step ulids whose save is in flight (the check shows busy). */
  busy: ReadonlySet<string>
  /** Step ulid -> inline error from a failed toggle. */
  rowErrors: Readonly<Record<string, string>>
}>()

const emit = defineEmits<{
  toggle: [step: WaveStepResource]
  edit: [step: WaveStepResource]
  remove: [step: WaveStepResource]
  add: [stream: WaveStream | null]
  removeStream: [stream: WaveStream]
  addStream: [stream: WaveStream]
}>()

const { t, locale } = useI18n()

const filter = ref<TrackerFilter>('all')
const expanded = ref<Set<WaveStream>>(new Set())

const FILTERS: readonly TrackerFilter[] = ['all', 'ugc_video', 'statics', 'stills', 'mine']
const FILTER_KEYS: Record<TrackerFilter, string> = {
  all: 'all',
  ugc_video: 'ugc',
  statics: 'statics',
  stills: 'stills',
  mine: 'mine',
}

const attrs = computed(() => props.tracker.attributes)
const today = computed(() => attrs.value.today)
const groups = computed(() => groupSteps(attrs.value.steps, filter.value, props.meUlid))
const dueWords = computed(() => ({
  today: t('app.waves.tracker.due.today'),
  tomorrow: t('app.waves.tracker.due.tomorrow'),
}))

function summaryOf(stream: WaveStream): WaveStreamSummary | undefined {
  return attrs.value.streams.find((s) => s.stream === stream)
}

function due(step: WaveStepResource): string | null {
  return formatDue(
    step.attributes.starts_on,
    step.attributes.due_on,
    today.value,
    locale.value,
    dueWords.value,
  )
}

function toggleExpanded(stream: WaveStream): void {
  const next = new Set(expanded.value)
  if (next.has(stream)) next.delete(stream)
  else next.add(stream)
  expanded.value = next
}

function completedLine(steps: readonly WaveStepResource[]): string {
  const c = completedSummary(steps)
  const count = t('app.waves.tracker.completed.count', { count: steps.length }, steps.length)
  const names = c.first === c.last ? c.first : `${c.first} → ${c.last}`
  const from = c.from === null ? null : shortDate(c.from, today.value, locale.value)
  const to = c.to === null ? null : shortDate(c.to, today.value, locale.value)
  const range =
    from === null || to === null ? '' : from === to ? ` (${from})` : ` (${from} – ${to})`
  return `${count} · ${names}${range}`
}

/** PL-2.5: what the real strategy step says next to a row somebody set by hand beyond it. */
function handLine(step: WaveStepResource): string {
  const st = step.attributes.strategy
  if (st === undefined) return ''
  const named = {
    state: t(`app.waves.tracker.stepStatus.${st.state}`),
    done: st.markets_done,
    total: st.markets_total,
  }
  return st.markets_total > 0
    ? t('app.waves.tracker.handSet.markets', named)
    : t('app.waves.tracker.handSet.plain', named)
}

function ownerLabel(step: WaveStepResource): { text: string; muted: boolean } {
  const a = step.attributes
  if (a.owner !== null) return { text: firstName(a.owner.name), muted: false }
  if (a.owner_client) return { text: t('app.waves.tracker.owner.client'), muted: false }
  if (a.owner_is_former) return { text: t('app.waves.tracker.owner.former'), muted: true }
  return { text: t('app.waves.tracker.owner.unassigned'), muted: true }
}

function groupDone(stream: WaveStream): { done: number; total: number } {
  const s = summaryOf(stream)
  return { done: s?.done ?? 0, total: s?.total ?? 0 }
}

function canRemove(step: WaveStepResource): boolean {
  return props.canManage && !step.attributes.is_strategy
}
</script>

<template>
  <section class="steps agency-card" data-test="steps-panel">
    <header class="steps__head">
      <h2 class="steps__title">{{ t('app.waves.tracker.steps.title') }}</h2>
      <div class="steps__filters" role="group" :aria-label="t('app.waves.tracker.steps.title')">
        <button
          v-for="f in FILTERS"
          :key="f"
          type="button"
          class="steps__filter"
          :class="{ 'steps__filter--on': filter === f }"
          :aria-pressed="filter === f"
          :data-test="`steps-filter-${f}`"
          @click="filter = f"
        >
          {{ t(`app.waves.tracker.filter.${FILTER_KEYS[f]}`) }}
        </button>
      </div>
    </header>

    <div class="steps__cols" aria-hidden="true">
      <span>{{ t('app.waves.tracker.col.step') }}</span>
      <span>{{ t('app.waves.tracker.col.owner') }}</span>
      <span>{{ t('app.waves.tracker.col.due') }}</span>
      <span>{{ t('app.waves.tracker.col.status') }}</span>
      <span />
    </div>

    <section
      v-for="g in groups"
      :key="g.stream"
      class="steps__group"
      :data-test="`steps-group-${g.stream}`"
    >
      <header class="steps__group-head">
        <span
          class="steps__swatch"
          :style="{ backgroundColor: summaryOf(g.stream)?.colour }"
          aria-hidden="true"
        />
        <h3 class="steps__group-name">{{ t(`app.waves.tracker.streams.${g.stream}`) }}</h3>
        <span class="steps__group-count">
          {{ t('app.waves.tracker.group.done', groupDone(g.stream)) }}
        </span>
        <v-menu v-if="canManage && g.stream !== 'ugc_video'" location="bottom end">
          <template #activator="{ props: menuProps }">
            <v-btn
              v-bind="menuProps"
              icon="mdi-dots-horizontal"
              variant="text"
              size="small"
              class="steps__group-menu"
              :aria-label="
                t('app.waves.tracker.actions.streamMenu', {
                  stream: t(`app.waves.tracker.streams.${g.stream}`),
                })
              "
              :data-test="`stream-menu-${g.stream}`"
            />
          </template>
          <v-list density="compact">
            <v-list-item
              :title="t('app.waves.tracker.actions.removeStream')"
              :data-test="`stream-remove-${g.stream}`"
              @click="emit('removeStream', g.stream)"
            />
          </v-list>
        </v-menu>
      </header>

      <div v-if="g.completed.length > 0" class="steps__completed" data-test="steps-completed">
        <span class="steps__completed-line">{{ completedLine(g.completed) }}</span>
        <button
          type="button"
          class="steps__link"
          :aria-expanded="expanded.has(g.stream)"
          :data-test="`steps-completed-toggle-${g.stream}`"
          @click="toggleExpanded(g.stream)"
        >
          {{
            expanded.has(g.stream)
              ? t('app.waves.tracker.completed.hide')
              : t('app.waves.tracker.completed.show')
          }}
        </button>
      </div>

      <template
        v-for="step in expanded.has(g.stream) ? [...g.completed, ...g.open] : g.open"
        :key="step.id"
      >
        <div
          class="steps__row"
          :class="{ 'steps__row--done': step.attributes.status === 'done' }"
          :data-test="`step-row-${step.id}`"
          :data-display-status="step.attributes.display_status"
        >
          <div class="steps__cell steps__cell--name">
            <button
              type="button"
              class="steps__check"
              :class="{ 'steps__check--on': step.attributes.status === 'done' }"
              :disabled="busy.has(step.id)"
              :aria-pressed="step.attributes.status === 'done'"
              :aria-label="
                step.attributes.status === 'done'
                  ? t('app.waves.tracker.actions.reopen', { name: step.attributes.name })
                  : t('app.waves.tracker.actions.markDone', { name: step.attributes.name })
              "
              :data-test="`step-check-${step.id}`"
              @click="emit('toggle', step)"
            >
              <v-icon v-if="step.attributes.status === 'done'" icon="mdi-check" size="14" />
            </button>
            <span class="steps__name" :data-test="`step-name-${step.id}`">{{
              step.attributes.name
            }}</span>
          </div>

          <div class="steps__cell steps__cell--owner">
            <button
              type="button"
              class="steps__cell-btn"
              :class="{ steps__muted: ownerLabel(step).muted }"
              :data-test="`step-owner-${step.id}`"
              @click="emit('edit', step)"
            >
              {{ ownerLabel(step).text }}
            </button>
          </div>

          <div class="steps__cell steps__cell--due">
            <button
              type="button"
              class="steps__cell-btn"
              :class="{ steps__muted: due(step) === null }"
              :data-test="`step-due-${step.id}`"
              @click="emit('edit', step)"
            >
              {{ due(step) ?? t('app.waves.tracker.card.noDate') }}
            </button>
          </div>

          <div class="steps__cell steps__cell--status">
            <AgencyStatusPill
              kind="wave_step"
              :status="step.attributes.display_status"
              :data-test="`step-status-${step.id}`"
            />
            <span
              v-if="step.attributes.strategy?.hand_set_beyond"
              class="steps__hand"
              :data-test="`step-hand-${step.id}`"
            >
              <v-icon icon="mdi-hand-back-right-outline" size="12" aria-hidden="true" />
              {{ handLine(step) }}
            </span>
            <span
              v-if="
                step.attributes.display_status === 'delayed' && step.attributes.days_overdue > 0
              "
              class="steps__overdue"
            >
              {{
                t(
                  'app.waves.tracker.steps.overdue',
                  { days: step.attributes.days_overdue },
                  step.attributes.days_overdue,
                )
              }}
            </span>
          </div>

          <div class="steps__cell steps__cell--menu">
            <v-menu location="bottom end">
              <template #activator="{ props: menuProps }">
                <v-btn
                  v-bind="menuProps"
                  icon="mdi-dots-horizontal"
                  variant="text"
                  size="small"
                  :aria-label="
                    t('app.waves.tracker.actions.rowMenu', { name: step.attributes.name })
                  "
                  :data-test="`step-menu-${step.id}`"
                />
              </template>
              <v-list density="compact">
                <v-list-item
                  :title="t('app.waves.tracker.actions.edit')"
                  :data-test="`step-edit-${step.id}`"
                  @click="emit('edit', step)"
                />
                <v-list-item
                  v-if="canRemove(step)"
                  :title="t('app.waves.tracker.actions.remove')"
                  :data-test="`step-remove-${step.id}`"
                  @click="emit('remove', step)"
                />
              </v-list>
            </v-menu>
          </div>
        </div>
        <p
          v-if="rowErrors[step.id]"
          class="steps__error"
          role="alert"
          :data-test="`step-error-${step.id}`"
        >
          {{ rowErrors[step.id] }}
        </p>
      </template>
    </section>

    <p v-if="groups.length === 0" class="steps__empty" data-test="steps-empty">
      {{ t('app.waves.tracker.empty.filtered') }}
    </p>

    <footer v-if="canManage" class="steps__foot">
      <button type="button" class="steps__link" data-test="step-add" @click="emit('add', null)">
        + {{ t('app.waves.tracker.actions.addStep') }}
      </button>
      <v-menu v-if="attrs.absent_streams.length > 0" location="top start">
        <template #activator="{ props: menuProps }">
          <button v-bind="menuProps" type="button" class="steps__link" data-test="stream-add">
            + {{ t('app.waves.tracker.actions.addStream') }}
          </button>
        </template>
        <v-list density="compact">
          <v-list-item
            v-for="s in STREAM_ORDER.filter((x) => attrs.absent_streams.includes(x))"
            :key="s"
            :title="t(`app.waves.tracker.streams.${s}`)"
            :data-test="`stream-add-${s}`"
            @click="emit('addStream', s)"
          />
        </v-list>
      </v-menu>
    </footer>
  </section>
</template>

<style scoped>
.steps {
  gap: 12px;
}

.steps__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}

.steps__title {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
}

.steps__filters {
  display: flex;
  gap: 6px;
  max-width: 100%;
  overflow-x: auto;
}

.steps__filter {
  flex: none;
  padding: 4px 12px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 999px;
  font-size: 13px;
  line-height: 20px;
  color: rgb(var(--v-theme-on-surface));
  background: transparent;
  cursor: pointer;
}

.steps__filter--on {
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-primary));
}

.steps__cols,
.steps__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 150px 150px 190px 40px;
  align-items: center;
  column-gap: 12px;
}

.steps__cols {
  padding: 0 4px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-text-tertiary));
}

.steps__group {
  display: flex;
  flex-direction: column;
  border-top: 1px solid rgb(var(--v-theme-border-color));
  padding-top: 8px;
}

.steps__group-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px;
}

.steps__swatch {
  width: 10px;
  height: 10px;
  border-radius: 3px;
}

.steps__group-name {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
}

.steps__group-count {
  font-size: 12px;
  color: rgb(var(--v-theme-text-tertiary));
}

.steps__group-menu {
  margin-inline-start: auto;
}

.steps__completed {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px;
  padding: 6px 4px;
  font-size: 13px;
  color: rgb(var(--v-theme-text-tertiary));
}

.steps__completed-line {
  min-width: 0;
  overflow-wrap: anywhere;
}

.steps__row {
  min-height: 44px;
  padding: 4px;
  border-radius: 8px;
}

.steps__cell {
  min-width: 0;
}

.steps__cell--name {
  display: flex;
  align-items: center;
  gap: 10px;
}

.steps__hand {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 2px;
  font-size: 11px;
  line-height: 14px;
  color: rgb(var(--v-theme-warning));
}
.steps__cell--status {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.steps__name {
  min-width: 0;
  overflow-wrap: anywhere;
  font-size: 14px;
}

.steps__row--done .steps__name {
  color: rgb(var(--v-theme-text-tertiary));
  text-decoration: line-through;
}

.steps__check {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border: 1.5px solid rgb(var(--v-theme-text-tertiary));
  border-radius: 50%;
  background: transparent;
  color: rgb(var(--v-theme-on-success));
  cursor: pointer;
}

.steps__check--on {
  border-color: rgb(var(--v-theme-success));
  background-color: rgb(var(--v-theme-success));
}

.steps__check:disabled {
  opacity: 0.5;
  cursor: progress;
}

.steps__cell-btn {
  max-width: 100%;
  padding: 2px 0;
  border: 0;
  background: transparent;
  font-size: 13px;
  text-align: start;
  color: inherit;
  cursor: pointer;
}

.steps__muted {
  color: rgb(var(--v-theme-text-tertiary));
}

.steps__overdue {
  font-size: 12px;
  color: rgb(var(--v-theme-error-text, var(--v-theme-error)));
}

.steps__error {
  margin: 0 0 4px 34px;
  font-size: 12px;
  color: rgb(var(--v-theme-error));
}

.steps__empty {
  margin: 8px 0;
  font-size: 14px;
  color: rgb(var(--v-theme-text-tertiary));
}

.steps__foot {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
  padding-top: 8px;
  border-top: 1px solid rgb(var(--v-theme-border-color));
}

.steps__link {
  padding: 0;
  border: 0;
  background: transparent;
  font-size: 13px;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
}

/* Below 768 px: no design exists, so a row stacks: name, then owner, due and status under it. */
@media (max-width: 767px) {
  .steps__cols {
    display: none;
  }

  .steps__row {
    grid-template-columns: minmax(0, 1fr) 40px;
    row-gap: 4px;
  }

  .steps__cell--name {
    grid-column: 1;
  }

  .steps__cell--menu {
    grid-column: 2;
    grid-row: 1;
  }

  .steps__cell--owner,
  .steps__cell--due,
  .steps__cell--status {
    grid-column: 1 / -1;
    padding-inline-start: 30px;
  }
}
</style>
