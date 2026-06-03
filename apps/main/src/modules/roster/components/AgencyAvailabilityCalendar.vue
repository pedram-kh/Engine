<script setup lang="ts">
/**
 * AgencyAvailabilityCalendar — READ-ONLY month view of a roster creator's
 * availability, as seen by the agency (Sprint 6 Chunk 2a, D-2a-9).
 *
 * This is NOT `AvailabilityCalendar.vue` (the creator-self calendar): that one
 * is creator-coupled — it imports the creator-self API, the create/edit dialog,
 * and has click-to-create / click-to-edit affordances. None of that belongs on
 * an agency read-view. So this is a NEW component that REUSES the genuinely
 * read-only-consumable pieces:
 *
 *   - the `CMonthGrid` layout primitive (emits prev/next/day-click, no edit
 *     coupling),
 *   - the pure tz/bucketing helpers from the creator availability module
 *     (`eachDayKey`, `monthLabel`, `monthQueryWindow`, `todayKey`,
 *     `weekdayLabels`, `zonedTime`) — pure functions, no Vue/store coupling,
 *   - `useResolvedTimezone` (renders in the VIEWING agency user's tz),
 *
 * and consumes the dedicated agency-availability API wrapper (the Sprint-5
 * endpoint, now with its consumer). The occurrences carry NO `reason` (the
 * dedicated `AgencyAvailability*` types omit it).
 *
 * Differences from the creator calendar — all subtractive:
 *   - no "Add block" button, no dialog, no click-to-create,
 *   - day cells / blocks are NOT clickable (no edit path),
 *   - the day-click event from CMonthGrid is ignored.
 */

import type { AgencyAvailabilityOccurrenceResource } from '@catalyst/api-client'
import { CEmptyState, CMonthGrid } from '@catalyst/ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  eachDayKey,
  monthLabel,
  monthQueryWindow,
  todayKey,
  weekdayLabels,
  zonedTime,
} from '@/modules/creators/availability/datetime'
import { useResolvedTimezone } from '@/modules/creators/availability/useResolvedTimezone'

import { agencyAvailabilityApi } from '../api/agencyAvailability.api'

const props = defineProps<{
  agencyId: string
  creatorUlid: string
}>()

const { t, locale } = useI18n()
const zone = useResolvedTimezone()

/** Max TIMED chips rendered per day cell before collapsing to a "+N". */
const MAX_TIMED_PER_CELL = 3

interface DayEntry {
  /** Composite cell key `id|starts_at` — recurring occurrences share the id. */
  key: string
  occurrence: AgencyAvailabilityOccurrenceResource
}

const initialToday = todayKey(zone.value)
const year = ref(Number(initialToday.slice(0, 4)))
const month = ref(Number(initialToday.slice(5, 7)))

const occurrences = ref<AgencyAvailabilityOccurrenceResource[]>([])
const loading = ref(false)
const hasLoadedOnce = ref(false)
const loadError = ref(false)

const gridMonthLabel = computed(() => monthLabel(year.value, month.value, locale.value))
const gridWeekdayLabels = computed(() => weekdayLabels(locale.value, 1))
const gridToday = computed(() => todayKey(zone.value))

/**
 * `'YYYY-MM-DD'` → the entries covering it, split into all-day vs timed.
 * Mirrors the creator calendar's bucketing (D-b5 composite key; multi-day
 * blocks paint each covered day, end-exclusive at midnight).
 */
const occurrencesByDay = computed(() => {
  const map = new Map<string, { allDay: DayEntry[]; timed: DayEntry[] }>()
  for (const occurrence of occurrences.value) {
    const entry: DayEntry = {
      key: `${occurrence.id}|${occurrence.attributes.starts_at}`,
      occurrence,
    }
    for (const day of eachDayKey(
      occurrence.attributes.starts_at,
      occurrence.attributes.ends_at,
      zone.value,
    )) {
      const bucket = map.get(day) ?? { allDay: [], timed: [] }
      if (occurrence.attributes.is_all_day) {
        bucket.allDay.push(entry)
      } else {
        bucket.timed.push(entry)
      }
      map.set(day, bucket)
    }
  }
  for (const bucket of map.values()) {
    const byStart = (a: DayEntry, b: DayEntry) =>
      a.occurrence.attributes.starts_at < b.occurrence.attributes.starts_at ? -1 : 1
    bucket.allDay.sort(byStart)
    bucket.timed.sort(byStart)
  }
  return map
})

const isEmpty = computed(
  () => hasLoadedOnce.value && !loadError.value && occurrences.value.length === 0,
)

function allDayEntries(date: string): DayEntry[] {
  return occurrencesByDay.value.get(date)?.allDay ?? []
}

function timedEntries(date: string): DayEntry[] {
  return occurrencesByDay.value.get(date)?.timed ?? []
}

function visibleTimed(date: string): DayEntry[] {
  return timedEntries(date).slice(0, MAX_TIMED_PER_CELL)
}

function timedOverflow(date: string): number {
  return Math.max(0, timedEntries(date).length - MAX_TIMED_PER_CELL)
}

function dayFillClass(date: string): string | null {
  const all = allDayEntries(date)
  if (all.length === 0) return null
  const hasHard = all.some((e) => e.occurrence.attributes.block_type === 'hard')
  return hasHard ? 'availability-fill--hard' : 'availability-fill--soft'
}

function barColor(occurrence: AgencyAvailabilityOccurrenceResource): string {
  return occurrence.attributes.block_type === 'hard' ? 'error' : 'warning'
}

function barLabel(occurrence: AgencyAvailabilityOccurrenceResource): string {
  const kindLabel = t(`availability.kind.${occurrence.attributes.kind}`)
  if (occurrence.attributes.is_all_day) {
    return `${t('availability.allDay')} · ${kindLabel}`
  }
  return `${zonedTime(occurrence.attributes.starts_at, zone.value)} ${kindLabel}`
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    const params = monthQueryWindow(year.value, month.value, zone.value)
    const response = await agencyAvailabilityApi.list(props.agencyId, props.creatorUlid, params)
    occurrences.value = response.data
  } catch {
    loadError.value = true
    occurrences.value = []
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

function goPrev(): void {
  if (month.value === 1) {
    month.value = 12
    year.value -= 1
  } else {
    month.value -= 1
  }
}

function goNext(): void {
  if (month.value === 12) {
    month.value = 1
    year.value += 1
  } else {
    month.value += 1
  }
}

watch([year, month, zone, () => props.creatorUlid], () => void load(), { immediate: true })
</script>

<template>
  <section data-test="agency-availability-calendar">
    <v-skeleton-loader
      v-if="loading && !hasLoadedOnce"
      type="image, image, image"
      data-test="agency-availability-skeleton"
    />

    <template v-else>
      <v-alert
        v-if="loadError"
        type="error"
        variant="tonal"
        class="mb-3"
        data-test="agency-availability-load-error"
      >
        {{ t('availability.errors.loadFailed') }}
      </v-alert>

      <CMonthGrid
        :year="year"
        :month="month"
        :month-label="gridMonthLabel"
        :weekday-labels="gridWeekdayLabels"
        :today="gridToday"
        :prev-label="t('availability.nav.prevMonth')"
        :next-label="t('availability.nav.nextMonth')"
        data-test="agency-availability-grid"
        @prev="goPrev"
        @next="goNext"
      >
        <template #day="{ cell }">
          <!-- Full-cell wash for an all-day-blocked day (read-only: no click
               target, no edit). -->
          <div
            v-if="dayFillClass(cell.date) !== null"
            class="availability-fill"
            :class="dayFillClass(cell.date)"
            :data-test="`agency-availability-fill-${cell.date}`"
            aria-hidden="true"
          />

          <!-- All-day labels: plain spans riding on the wash (not buttons). -->
          <span
            v-for="entry in allDayEntries(cell.date)"
            :key="entry.key"
            class="availability-allday"
            :data-test="`agency-availability-bar-${entry.key}`"
            :title="barLabel(entry.occurrence)"
          >
            <span class="availability-bar__label">{{ barLabel(entry.occurrence) }}</span>
          </span>

          <!-- Timed blocks: small tonal chips (read-only, non-clickable). -->
          <v-chip
            v-for="entry in visibleTimed(cell.date)"
            :key="entry.key"
            size="x-small"
            label
            variant="tonal"
            :color="barColor(entry.occurrence)"
            class="availability-bar"
            :data-test="`agency-availability-bar-${entry.key}`"
            :title="barLabel(entry.occurrence)"
          >
            <span class="availability-bar__label">{{ barLabel(entry.occurrence) }}</span>
          </v-chip>

          <!-- Overflow indicator — plain caption, no popover (read-only). -->
          <span
            v-if="timedOverflow(cell.date) > 0"
            class="availability-more text-caption text-medium-emphasis"
            :data-test="`agency-availability-overflow-${cell.date}`"
          >
            {{ t('availability.moreCount', { count: timedOverflow(cell.date) }) }}
          </span>
        </template>
      </CMonthGrid>

      <CEmptyState
        v-if="isEmpty"
        class="mt-4"
        data-test="agency-availability-empty"
        :title="t('app.roster.detail.availability.empty.heading')"
        :body="t('app.roster.detail.availability.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-calendar-blank-outline" size="64" color="medium-emphasis" />
        </template>
      </CEmptyState>
    </template>
  </section>
</template>

<style scoped>
.availability-fill {
  position: absolute;
  inset: 0;
  z-index: -1;
  pointer-events: none;
}
.availability-fill--hard {
  background: rgb(var(--v-theme-error));
}
.availability-fill--soft {
  background: rgb(var(--v-theme-warning));
}

.availability-allday {
  position: relative;
  z-index: 1;
  display: block;
  width: 100%;
  text-align: left;
  padding: 2px var(--space-2, 8px);
  border-radius: var(--radius-sm, 4px);
  font-size: var(--catalyst-typography-caption-size, 0.75rem);
  font-weight: 600;
  color: rgb(var(--v-theme-on-error));
}
.availability-fill--soft ~ .availability-allday {
  color: rgb(var(--v-theme-on-warning));
}

.availability-bar {
  position: relative;
  z-index: 1;
  width: 100%;
  justify-content: flex-start;
}

.availability-bar__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.availability-more {
  position: relative;
  z-index: 1;
  align-self: flex-start;
  padding: 0 var(--space-1, 4px);
}
</style>
