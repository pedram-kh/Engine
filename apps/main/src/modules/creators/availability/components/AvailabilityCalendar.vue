<script setup lang="ts">
/**
 * AvailabilityCalendar — month view of the creator's availability blocks
 * (Sprint 5 Chunk B, D-b1…D-b7).
 *
 * Wraps the shared, tz-agnostic `CMonthGrid` layout primitive and owns all
 * the availability-specific logic:
 *
 *   - Fetches occurrences for the visible month from
 *     `GET /creators/me/availability` (the api wrapper), reading the
 *     EFFECTIVE range from `meta.window` (D-b6 — the requested span is
 *     silently clamped at 366d).
 *   - Renders everything in the creator's RESOLVED timezone (D-b7): a UTC
 *     instant lands in the correct day cell for that tz; a null user tz
 *     falls back to the browser tz.
 *   - ⚠ Buckets occurrences by day, keyed `id + starts_at` (D-b5) — every
 *     occurrence of a recurring block shares the block ULID, so an `id`-only
 *     key would collide. The composite key is per-cell-unique.
 *   - Multi-day blocks paint each covered day (day-level bars, end-exclusive
 *     at midnight). This is NOT intra-day lane math — that overlap geometry
 *     is the deferred week view (D-b1).
 *   - Click a day → create; click a block bar → edit/delete the series.
 */

import type { AvailabilityOccurrenceResource } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { CMonthGrid } from '@catalyst/ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { availabilityApi } from '../availability.api'
import {
  eachDayKey,
  monthLabel,
  monthQueryWindow,
  todayKey,
  weekdayLabels,
  zonedTime,
} from '../datetime'
import { useResolvedTimezone } from '../useResolvedTimezone'
import AvailabilityBlockDialog from './AvailabilityBlockDialog.vue'

const { t, locale } = useI18n()
const zone = useResolvedTimezone()

/** Max bars rendered per day cell before collapsing to a "+N" indicator. */
const MAX_BARS_PER_CELL = 3

interface DayEntry {
  /** Composite cell key `id|starts_at` (D-b5). */
  key: string
  occurrence: AvailabilityOccurrenceResource
}

// Current month — seeded to "today" in the resolved tz.
const initialToday = todayKey(zone.value)
const year = ref(Number(initialToday.slice(0, 4)))
const month = ref(Number(initialToday.slice(5, 7)))

const occurrences = ref<AvailabilityOccurrenceResource[]>([])
/** The effective window the backend expanded (read from `meta.window`, D-b6). */
const loadedWindow = ref<{ from: string; to: string } | null>(null)
const loading = ref(false)
const hasLoadedOnce = ref(false)
const loadError = ref(false)

const dialogOpen = ref(false)
const editingOccurrence = ref<AvailabilityOccurrenceResource | null>(null)
const createSeedDate = ref<string | null>(null)

const gridMonthLabel = computed(() => monthLabel(year.value, month.value, locale.value))
const gridWeekdayLabels = computed(() => weekdayLabels(locale.value, 1))
const gridToday = computed(() => todayKey(zone.value))

const occurrencesByDay = computed(() => {
  const map = new Map<string, DayEntry[]>()
  for (const occurrence of occurrences.value) {
    const key = `${occurrence.id}|${occurrence.attributes.starts_at}`
    for (const day of eachDayKey(
      occurrence.attributes.starts_at,
      occurrence.attributes.ends_at,
      zone.value,
    )) {
      const bucket = map.get(day) ?? []
      bucket.push({ key, occurrence })
      map.set(day, bucket)
    }
  }
  for (const bucket of map.values()) {
    bucket.sort((a, b) =>
      a.occurrence.attributes.starts_at < b.occurrence.attributes.starts_at ? -1 : 1,
    )
  }
  return map
})

const isEmpty = computed(
  () => hasLoadedOnce.value && !loadError.value && occurrences.value.length === 0,
)

function entriesFor(date: string): DayEntry[] {
  return occurrencesByDay.value.get(date) ?? []
}

function visibleEntries(date: string): DayEntry[] {
  return entriesFor(date).slice(0, MAX_BARS_PER_CELL)
}

function overflowCount(date: string): number {
  return Math.max(0, entriesFor(date).length - MAX_BARS_PER_CELL)
}

function barColor(occurrence: AvailabilityOccurrenceResource): string {
  return occurrence.attributes.block_type === 'hard' ? 'error' : 'warning'
}

function barLabel(occurrence: AvailabilityOccurrenceResource): string {
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
    const response = await availabilityApi.list(params)
    occurrences.value = response.data
    // Read the EFFECTIVE range from meta.window, not the requested `to`
    // (the 366-day clamp is silent — D-b6).
    loadedWindow.value = response.meta.window
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

function openCreate(date: string): void {
  editingOccurrence.value = null
  createSeedDate.value = date
  dialogOpen.value = true
}

function openEdit(occurrence: AvailabilityOccurrenceResource): void {
  editingOccurrence.value = occurrence
  createSeedDate.value = null
  dialogOpen.value = true
}

function onMutated(): void {
  void load()
}

watch([year, month, zone], () => void load(), { immediate: true })

defineExpose({ loadedWindow, year, month })
</script>

<template>
  <section data-test="availability-calendar">
    <div class="d-flex justify-end mb-3">
      <v-btn
        color="primary"
        variant="flat"
        prepend-icon="mdi-plus"
        data-test="availability-add"
        @click="openCreate(gridToday)"
      >
        {{ t('availability.actions.add') }}
      </v-btn>
    </div>

    <v-skeleton-loader
      v-if="loading && !hasLoadedOnce"
      type="image, image, image"
      data-test="availability-skeleton"
    />

    <template v-else>
      <v-alert
        v-if="loadError"
        type="error"
        variant="tonal"
        class="mb-3"
        data-test="availability-load-error"
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
        data-test="availability-grid"
        @prev="goPrev"
        @next="goNext"
        @day-click="openCreate"
      >
        <template #day="{ cell }">
          <v-chip
            v-for="entry in visibleEntries(cell.date)"
            :key="entry.key"
            size="x-small"
            label
            variant="tonal"
            :color="barColor(entry.occurrence)"
            class="availability-bar"
            :data-test="`availability-bar-${entry.key}`"
            :title="barLabel(entry.occurrence)"
            @click.stop="openEdit(entry.occurrence)"
          >
            <span class="availability-bar__label">{{ barLabel(entry.occurrence) }}</span>
          </v-chip>
          <span
            v-if="overflowCount(cell.date) > 0"
            class="text-caption text-medium-emphasis"
            :data-test="`availability-overflow-${cell.date}`"
          >
            {{ t('availability.moreCount', { count: overflowCount(cell.date) }) }}
          </span>
        </template>
      </CMonthGrid>

      <CEmptyState
        v-if="isEmpty"
        class="mt-4"
        data-test="availability-empty"
        :title="t('availability.empty.heading')"
        :body="t('availability.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-calendar-blank-outline" size="64" color="medium-emphasis" />
        </template>
      </CEmptyState>
    </template>

    <AvailabilityBlockDialog
      v-model="dialogOpen"
      :occurrence="editingOccurrence"
      :initial-date="createSeedDate"
      :zone="zone"
      @saved="onMutated"
      @deleted="onMutated"
    />
  </section>
</template>

<style scoped>
.availability-bar {
  width: 100%;
  justify-content: flex-start;
  cursor: pointer;
}

.availability-bar__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
