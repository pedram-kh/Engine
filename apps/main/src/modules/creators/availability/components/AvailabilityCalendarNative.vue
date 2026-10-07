<script setup lang="ts">
/**
 * AvailabilityCalendarNative — the creator app's availability month
 * (AH-100, Figma frame 12): a compact `CMonthGrid` that only marks which
 * days are blocked, a legend + "Add block" row, and the month's blocks
 * listed by day beneath it (the agenda), where the web calendar stacks
 * chips inside 96px cells.
 *
 * Same data, same actions as `AvailabilityCalendar` — both read
 * `useAvailabilityMonth` — so a block shows in one shell exactly when it
 * shows in the other. Tapping a day creates; tapping an agenda row edits
 * its series.
 *
 * Marks: a day an all-day block covers is tinted (hard wins over soft); a
 * day with only timed blocks gets a dot. The agenda lists the displayed
 * month's days only; padding days from the neighbouring months carry their
 * mark but are listed when their own month is shown.
 */

import type { AvailabilityOccurrenceResource } from '@catalyst/api-client'
import { CMonthGrid } from '@catalyst/ui'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { agendaDayLabel } from '../datetime'
import { type DayEntry, useAvailabilityMonth } from '../useAvailabilityMonth'
import AvailabilityBlockDialog from './AvailabilityBlockDialog.vue'

const { t, locale } = useI18n()

const {
  zone,
  year,
  month,
  loading,
  hasLoadedOnce,
  loadError,
  dialogOpen,
  editingOccurrence,
  createSeedDate,
  gridMonthLabel,
  gridWeekdayLabels,
  gridToday,
  occurrencesByDay,
  isEmpty,
  allDayEntries,
  timedEntries,
  barLabel,
  goPrev,
  goNext,
  openCreate,
  openEdit,
  onMutated,
} = useAvailabilityMonth()

function isHard(entry: DayEntry): boolean {
  return entry.occurrence.attributes.block_type === 'hard'
}

/** The cell mark for a day: a tint for all-day cover, a dot for timed-only. */
function dayMark(date: string): string | null {
  const allDay = allDayEntries(date)
  if (allDay.length > 0) {
    return allDay.some(isHard) ? 'fill-hard' : 'fill-soft'
  }
  const timed = timedEntries(date)
  if (timed.length > 0) {
    return timed.some(isHard) ? 'dot-hard' : 'dot-soft'
  }
  return null
}

function blockTypeClass(occurrence: AvailabilityOccurrenceResource): string {
  return `availability-native__swatch--${occurrence.attributes.block_type}`
}

/** The displayed month's days that carry blocks, in date order. */
const agendaDays = computed(() => {
  const prefix = `${year.value}-${String(month.value).padStart(2, '0')}-`
  return [...occurrencesByDay.value.keys()]
    .filter((date) => date.startsWith(prefix))
    .sort()
    .map((date) => ({ date, entries: [...allDayEntries(date), ...timedEntries(date)] }))
})
</script>

<template>
  <section class="availability-native" data-test="availability-calendar-native">
    <v-skeleton-loader
      v-if="loading && !hasLoadedOnce"
      type="image, image, image"
      data-test="availability-skeleton"
    />

    <template v-else>
      <v-alert v-if="loadError" type="error" variant="tonal" data-test="availability-load-error">
        {{ t('availability.errors.loadFailed') }}
      </v-alert>

      <CMonthGrid
        class="availability-native__grid"
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
          <span
            v-if="dayMark(cell.date) !== null"
            class="availability-native__mark"
            :class="`availability-native__mark--${dayMark(cell.date)}`"
            :data-test="`availability-native-mark-${cell.date}`"
            aria-hidden="true"
          />
        </template>
      </CMonthGrid>

      <div class="availability-native__legend-row">
        <span class="availability-native__legend">
          <span class="availability-native__swatch availability-native__swatch--today" />
          {{ t('availability.legend.today') }}
        </span>
        <span class="availability-native__legend">
          <span class="availability-native__swatch availability-native__swatch--hard" />
          {{ t('availability.blockType.hard') }}
        </span>
        <span class="availability-native__legend">
          <span class="availability-native__swatch availability-native__swatch--soft" />
          {{ t('availability.blockType.soft') }}
        </span>
        <v-spacer />
        <v-btn
          color="primary"
          variant="flat"
          data-test="availability-add"
          @click="openCreate(gridToday)"
        >
          {{ t('availability.actions.add') }}
        </v-btn>
      </div>

      <ol
        v-if="agendaDays.length > 0"
        class="availability-native__agenda"
        data-test="availability-agenda"
      >
        <li
          v-for="day in agendaDays"
          :key="day.date"
          class="availability-native__day"
          :data-test="`availability-agenda-day-${day.date}`"
        >
          <p class="availability-native__day-label">{{ agendaDayLabel(day.date, locale) }}</p>
          <button
            v-for="entry in day.entries"
            :key="entry.key"
            type="button"
            class="availability-native__row"
            :data-test="`availability-agenda-item-${entry.key}`"
            @click="openEdit(entry.occurrence)"
          >
            <span class="availability-native__swatch" :class="blockTypeClass(entry.occurrence)" />
            <span class="availability-native__row-label">{{ barLabel(entry.occurrence) }}</span>
            <v-icon icon="mdi-chevron-right" size="16" class="availability-native__row-chevron" />
          </button>
        </li>
      </ol>

      <div v-if="isEmpty" class="availability-native__empty" data-test="availability-empty">
        <span class="availability-native__empty-icon">
          <v-icon icon="mdi-calendar-blank-outline" size="24" />
        </span>
        <p class="availability-native__empty-title">{{ t('availability.empty.heading') }}</p>
        <p class="availability-native__empty-body">{{ t('availability.empty.body') }}</p>
      </div>
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
/* Figma frame 12. CMonthGrid's own rules are restyled from here through
   :deep() so the shared primitive (and the web calendar) stay untouched. */
.availability-native {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.availability-native__grid :deep(.cmg__title) {
  font: var(--native-type-h3);
  color: var(--native-text-primary);
}

.availability-native__grid :deep(.cmg__nav) {
  border-color: var(--native-border-default);
  border-radius: var(--native-radius-lg);
  color: var(--native-text-primary);
}

.availability-native__grid :deep(.cmg__grid) {
  background: var(--native-bg-surface);
  border-color: var(--native-border-default);
  border-radius: var(--native-radius-2xl);
}

.availability-native__grid :deep(.cmg__weekdays) {
  background: transparent;
}

.availability-native__grid :deep(.cmg__weekday) {
  padding: 10px 0;
  font: var(--native-type-overline);
  letter-spacing: var(--native-type-overline-tracking);
  color: var(--native-text-tertiary);
}

.availability-native__grid :deep(.cmg__weeks) {
  grid-auto-rows: 52px;
}

.availability-native__grid :deep(.cmg__cell) {
  padding: 6px 6px 0 0;
  border-color: var(--native-border-default);
  border-top: 1px solid var(--native-border-default);
  background: transparent;
  color: var(--native-text-secondary);
}

.availability-native__grid :deep(.cmg__cell:nth-child(-n + 7)) {
  border-top: 1px solid var(--native-border-default);
}

.availability-native__grid :deep(.cmg__cell--muted) {
  background: var(--native-bg-canvas);
  color: var(--native-text-tertiary);
  opacity: 1;
}

.availability-native__grid :deep(.cmg__daynum) {
  font: var(--native-type-caption);
}

.availability-native__grid :deep(.cmg__cell--today .cmg__daynum) {
  background: var(--native-accent-primary);
  color: var(--native-text-on-accent);
  font: var(--native-type-caption-strong);
}

.availability-native__mark--fill-hard,
.availability-native__mark--fill-soft {
  position: absolute;
  inset: 0;
  z-index: -1;
}

.availability-native__mark--fill-hard {
  background: var(--native-status-danger-subtle);
}

.availability-native__mark--fill-soft {
  background: var(--native-status-warning-subtle);
}

.availability-native__mark--dot-hard,
.availability-native__mark--dot-soft {
  align-self: flex-end;
  width: 6px;
  height: 6px;
  margin-right: 9px;
  border-radius: var(--native-radius-full);
}

.availability-native__mark--dot-hard {
  background: var(--native-status-danger);
}

.availability-native__mark--dot-soft {
  background: var(--native-status-warning);
}

.availability-native__legend-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
}

.availability-native__legend {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font: var(--native-type-caption);
  color: var(--native-text-secondary);
}

.availability-native__swatch {
  flex-shrink: 0;
  width: 10px;
  height: 10px;
  border-radius: var(--native-radius-sm);
}

.availability-native__swatch--today {
  background: var(--native-accent-primary);
}

.availability-native__swatch--hard {
  background: var(--native-status-danger);
}

.availability-native__swatch--soft {
  background: var(--native-status-warning);
}

.availability-native__agenda {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.availability-native__day {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.availability-native__day-label {
  margin: 0;
  font: var(--native-type-overline);
  letter-spacing: var(--native-type-overline-tracking);
  text-transform: uppercase;
  color: var(--native-text-tertiary);
}

.availability-native__row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 44px;
  padding: 12px 16px;
  border: 1px solid var(--native-border-default);
  border-radius: var(--native-radius-xl);
  background: var(--native-bg-surface);
  color: var(--native-text-primary);
  font: var(--native-type-body);
  text-align: left;
}

.availability-native__row-label {
  flex: 1 0 0;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.availability-native__row-chevron {
  color: var(--native-text-tertiary);
}

.availability-native__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 24px 0;
  text-align: center;
}

.availability-native__empty-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 52px;
  height: 52px;
  margin-bottom: 4px;
  border: 1px solid var(--native-border-default);
  border-radius: var(--native-radius-full);
  background: var(--native-bg-surface);
  color: var(--native-text-secondary);
}

.availability-native__empty-title {
  margin: 0;
  font: var(--native-type-h4);
  color: var(--native-text-primary);
}

.availability-native__empty-body {
  margin: 0;
  font: var(--native-type-body-small);
  color: var(--native-text-secondary);
}
</style>
