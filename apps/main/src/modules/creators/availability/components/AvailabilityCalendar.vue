<script setup lang="ts">
/**
 * AvailabilityCalendar — month view of the creator's availability blocks
 * (Sprint 5 Chunk B, D-b1…D-b7).
 *
 * Wraps the shared, tz-agnostic `CMonthGrid` layout primitive and owns all
 * the availability-specific logic:
 *
 *   - Fetch, tz bucketing (D-b5…D-b7), month navigation and the dialog
 *     state live in `useAvailabilityMonth` (shared with the creator app's
 *     `AvailabilityCalendarNative`).
 *   - All-day blocks FILL their day cell: a full-cell colour wash (per
 *     covered day, so a multi-day block tints each spanned day) makes the
 *     day read as blocked, with the block label clickable on top.
 *   - Timed blocks render as small tonal chips stacked on top of the wash,
 *     prefixed with the start time.
 *   - Multi-day blocks paint each covered day (day-level, end-exclusive at
 *     midnight). This is NOT intra-day lane math — that overlap geometry is
 *     the deferred week view (D-b1).
 *   - Click a day → create; click a block → edit/delete the series.
 */

import type { AvailabilityOccurrenceResource } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { CMonthGrid } from '@catalyst/ui'
import { useI18n } from 'vue-i18n'

import { type DayEntry, useAvailabilityMonth } from '../useAvailabilityMonth'
import AvailabilityBlockDialog from './AvailabilityBlockDialog.vue'

const { t } = useI18n()

/** Max TIMED chips rendered per day cell before collapsing to a "+N". */
const MAX_TIMED_PER_CELL = 3

const {
  zone,
  year,
  month,
  loadedWindow,
  loading,
  hasLoadedOnce,
  loadError,
  dialogOpen,
  editingOccurrence,
  createSeedDate,
  gridMonthLabel,
  gridWeekdayLabels,
  gridToday,
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

function visibleTimed(date: string): DayEntry[] {
  return timedEntries(date).slice(0, MAX_TIMED_PER_CELL)
}

function timedOverflow(date: string): number {
  return Math.max(0, timedEntries(date).length - MAX_TIMED_PER_CELL)
}

/** Every block on a day (all-day first, then timed) for the "more" popover. */
function allEntries(date: string): DayEntry[] {
  return [...allDayEntries(date), ...timedEntries(date)]
}

/**
 * The full-cell wash modifier for a day, by the strongest all-day block on
 * it (a HARD block wins over SOFT). `null` when no all-day block covers it.
 */
function dayFillClass(date: string): string | null {
  const all = allDayEntries(date)
  if (all.length === 0) return null
  const hasHard = all.some((e) => e.occurrence.attributes.block_type === 'hard')
  return hasHard ? 'availability-fill--hard' : 'availability-fill--soft'
}

function barColor(occurrence: AvailabilityOccurrenceResource): string {
  return occurrence.attributes.block_type === 'hard' ? 'error' : 'warning'
}

/**
 * Edit the all-day block covering a cell when the creator clicks anywhere
 * on the colour wash (not just the thin label). The wash itself is a
 * full-cell click target on all-day days so a creator can always reach the
 * editor — and therefore Delete — for an all-day / multi-day block, instead
 * of accidentally hitting the empty-cell "create" handler. Targets the first
 * all-day entry; individual labels/chips (raised above the overlay) still
 * select a specific block when a day stacks several.
 */
function openEditAllDay(date: string): void {
  const occurrence = allDayEntries(date)[0]?.occurrence
  if (occurrence !== undefined) {
    openEdit(occurrence)
  }
}

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
          <!-- Full-cell wash: the day reads as blocked (z-index:-1 sits
               behind the day number + chips via the cell's isolation). -->
          <div
            v-if="dayFillClass(cell.date) !== null"
            class="availability-fill"
            :class="dayFillClass(cell.date)"
            :data-test="`availability-fill-${cell.date}`"
            aria-hidden="true"
          />

          <!-- Full-cell click target for an all-day block: the whole washed
               area opens the editor (so Delete is always reachable), instead
               of the empty space triggering "create". Sits above the wash but
               below the labels/chips (z-index), so a specific block can still
               be selected when a day stacks several. -->
          <button
            v-if="allDayEntries(cell.date).length > 0"
            type="button"
            class="availability-fill-click"
            :data-test="`availability-fill-click-${cell.date}`"
            :aria-label="t('availability.dialog.editTitle')"
            @click.stop="openEditAllDay(cell.date)"
          />

          <!-- All-day blocks: a clickable label riding on the wash. -->
          <button
            v-for="entry in allDayEntries(cell.date)"
            :key="entry.key"
            type="button"
            class="availability-allday"
            :data-test="`availability-bar-${entry.key}`"
            :title="barLabel(entry.occurrence)"
            @click.stop="openEdit(entry.occurrence)"
          >
            <span class="availability-bar__label">{{ barLabel(entry.occurrence) }}</span>
          </button>

          <!-- Timed blocks: small tonal chips on top of the wash. -->
          <v-chip
            v-for="entry in visibleTimed(cell.date)"
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

          <!-- Overflow: a clickable "+N more" that opens a popover listing
               EVERY block on the day, each one editable. -->
          <v-menu
            v-if="timedOverflow(cell.date) > 0"
            location="bottom start"
            :close-on-content-click="true"
          >
            <template #activator="{ props: menuProps }">
              <button
                type="button"
                class="availability-more text-caption text-medium-emphasis"
                v-bind="menuProps"
                :data-test="`availability-overflow-${cell.date}`"
                :aria-label="t('availability.dayPopover.viewAll')"
                @click.stop
              >
                {{ t('availability.moreCount', { count: timedOverflow(cell.date) }) }}
              </button>
            </template>

            <v-card min-width="240" :data-test="`availability-day-list-${cell.date}`">
              <v-list density="compact" lines="one">
                <v-list-subheader>{{ t('availability.dayPopover.title') }}</v-list-subheader>
                <v-list-item
                  v-for="entry in allEntries(cell.date)"
                  :key="entry.key"
                  :data-test="`availability-day-item-${entry.key}`"
                  @click="openEdit(entry.occurrence)"
                >
                  <template #prepend>
                    <v-icon :color="barColor(entry.occurrence)" icon="mdi-circle" size="x-small" />
                  </template>
                  <v-list-item-title>{{ barLabel(entry.occurrence) }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-card>
          </v-menu>
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
/* Full-cell colour wash for an all-day-blocked day. Bleeds over the cell
   padding to the border, and sits BEHIND the day number + chips (the cell
   is an isolated stacking context in CMonthGrid, so z-index:-1 is scoped). */
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

/* Transparent full-cell click target on all-day days. Sits above the wash
   (z-index:0 vs the wash's -1) so clicking anywhere on the block opens the
   editor; transparent, so the day number painted in normal flow shows
   through. The labels/chips below are raised to z-index:1 so they stay
   individually clickable on top of this overlay. */
.availability-fill-click {
  position: absolute;
  inset: 0;
  z-index: 0;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
}

/* All-day label riding on the wash — fills the width, reads on the colour. */
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
  cursor: pointer;
  background: transparent;
}
.availability-fill--soft ~ .availability-allday {
  color: rgb(var(--v-theme-on-warning));
}

.availability-bar {
  position: relative;
  z-index: 1;
  width: 100%;
  justify-content: flex-start;
  cursor: pointer;
}

.availability-bar__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Clickable "+N more" — left-aligned, looks like inline text but is a real
   button so it opens the day popover (and stays keyboard-focusable). */
.availability-more {
  position: relative;
  z-index: 1;
  align-self: flex-start;
  padding: 0 var(--space-1, 4px);
  background: transparent;
  border-radius: var(--radius-sm, 4px);
  cursor: pointer;
  text-align: left;
}
.availability-more:hover {
  text-decoration: underline;
}
</style>
