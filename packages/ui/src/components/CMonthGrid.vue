<script setup lang="ts">
/**
 * CMonthGrid — a pure, dependency-free month-calendar LAYOUT primitive
 * (Sprint 5 Chunk B, D-b2).
 *
 * Renders a 6×7 day matrix with leading/trailing-month padding, a weekday
 * header, a localized month label, prev/next navigation, and a today
 * marker. It owns ONLY the calendar geometry — it has no knowledge of
 * timezones, occurrences, or i18n:
 *
 *   - Date math is pure calendar arithmetic on UTC `Date`s (so it never
 *     drifts with the host machine's local timezone / DST). The grid emits
 *     and slots plain `'YYYY-MM-DD'` date keys; the consumer does any
 *     timezone work (Luxon lives in the app, not here — D-b3/D-b7).
 *   - All user-facing strings (`monthLabel`, `weekdayLabels`, the nav
 *     button aria-labels) are passed in already-localized, mirroring the
 *     i18n-free contract of `CEmptyState`.
 *
 * The consumer fills each day cell via the scoped `#day` slot, which
 * receives a single `cell` object `{ date, day, inMonth, isToday }` (a
 * single object avoids the template slot-prop camelCase gotcha) — e.g. the
 * availability calendar tints the cell for all-day blocks and stacks timed
 * blocks as chips on top. The cell is a stacking context (`isolation`), so
 * a slotted full-cell background layer (`z-index: -1`) paints behind the
 * day number and chips.
 *
 * Styling consumes the Vuetify theme layer (`rgb(var(--v-theme-*))`) so it
 * re-themes automatically across light/dark with no extra work.
 */

import { computed } from 'vue'

interface Props {
  /** Full year, e.g. 2026. */
  year: number
  /** Month number, 1–12 (NOT zero-based). */
  month: number
  /** Localized weekday headers, length 7, in display order from `weekStartsOn`. */
  weekdayLabels: readonly string[]
  /** Localized month + year header, e.g. "June 2026". */
  monthLabel: string
  /** Today's date as `'YYYY-MM-DD'` in the consumer's resolved tz, or null. */
  today?: string | null
  /** 0 = Sunday-first, 1 = Monday-first. Default Monday (en/pt/it lean EU). */
  weekStartsOn?: 0 | 1
  /** aria-label for the previous-month button. */
  prevLabel?: string
  /** aria-label for the next-month button. */
  nextLabel?: string
  /** Root `data-test` anchor. */
  dataTest?: string
}

const props = withDefaults(defineProps<Props>(), {
  today: null,
  weekStartsOn: 1,
  prevLabel: 'Previous month',
  nextLabel: 'Next month',
  dataTest: undefined,
})

const emit = defineEmits<{
  prev: []
  next: []
  'day-click': [date: string]
}>()

interface DayCell {
  /** `'YYYY-MM-DD'`. */
  date: string
  /** Day-of-month number, 1–31. */
  day: number
  /** Whether the cell belongs to the displayed month (vs padding). */
  inMonth: boolean
  isToday: boolean
}

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

function isoDate(year: number, month1: number, day: number): string {
  return `${year}-${pad2(month1)}-${pad2(day)}`
}

const cells = computed<DayCell[]>(() => {
  // Day-of-week of the 1st (0=Sun … 6=Sat), computed in UTC so the result
  // is independent of the runner's local timezone.
  const firstDow = new Date(Date.UTC(props.year, props.month - 1, 1)).getUTCDay()
  const lead = (firstDow - props.weekStartsOn + 7) % 7
  const start = new Date(Date.UTC(props.year, props.month - 1, 1 - lead))

  const out: DayCell[] = []
  for (let i = 0; i < 42; i++) {
    const d = new Date(start)
    d.setUTCDate(start.getUTCDate() + i)
    const y = d.getUTCFullYear()
    const m = d.getUTCMonth() + 1
    const day = d.getUTCDate()
    const date = isoDate(y, m, day)
    out.push({
      date,
      day,
      inMonth: m === props.month && y === props.year,
      isToday: props.today != null && date === props.today,
    })
  }
  return out
})

function onCellActivate(date: string): void {
  emit('day-click', date)
}
</script>

<template>
  <div class="cmg" :data-test="dataTest">
    <header class="cmg__bar">
      <button
        type="button"
        class="cmg__nav"
        :aria-label="prevLabel"
        data-test="cmg-prev"
        @click="emit('prev')"
      >
        <span aria-hidden="true">&#8249;</span>
      </button>
      <h2 class="cmg__title" data-test="cmg-title">{{ monthLabel }}</h2>
      <button
        type="button"
        class="cmg__nav"
        :aria-label="nextLabel"
        data-test="cmg-next"
        @click="emit('next')"
      >
        <span aria-hidden="true">&#8250;</span>
      </button>
    </header>

    <div class="cmg__grid" role="grid">
      <div class="cmg__weekdays" role="row">
        <div v-for="label in weekdayLabels" :key="label" class="cmg__weekday" role="columnheader">
          {{ label }}
        </div>
      </div>

      <div class="cmg__weeks">
        <div
          v-for="cell in cells"
          :key="cell.date"
          class="cmg__cell"
          :class="{ 'cmg__cell--muted': !cell.inMonth, 'cmg__cell--today': cell.isToday }"
          role="gridcell"
          tabindex="0"
          :data-date="cell.date"
          :data-in-month="cell.inMonth"
          :aria-current="cell.isToday ? 'date' : undefined"
          @click="onCellActivate(cell.date)"
          @keydown.enter.prevent="onCellActivate(cell.date)"
          @keydown.space.prevent="onCellActivate(cell.date)"
        >
          <span class="cmg__daynum">{{ cell.day }}</span>
          <div class="cmg__daybody">
            <slot name="day" :cell="cell" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cmg {
  display: flex;
  flex-direction: column;
  gap: var(--space-3, 12px);
}

.cmg__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3, 12px);
}

.cmg__title {
  margin: 0;
  text-align: center;
  flex: 1;
  font-size: var(--catalyst-typography-heading-3-size);
  font-weight: var(--catalyst-typography-heading-3-weight, 600);
  color: rgb(var(--v-theme-on-surface));
}

.cmg__nav {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-md, 8px);
  font-size: var(--catalyst-typography-heading-3-size);
  line-height: 1;
  cursor: pointer;
  background: transparent;
  border: 1px solid rgb(var(--v-theme-outline-variant));
  color: rgb(var(--v-theme-on-surface));
}

.cmg__nav:hover {
  background: rgb(var(--v-theme-surface-variant));
}

.cmg__grid {
  border: 1px solid rgb(var(--v-theme-outline-variant));
  border-radius: var(--radius-lg, 12px);
  overflow: hidden;
}

.cmg__weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  background: rgb(var(--v-theme-surface-variant));
}

.cmg__weekday {
  padding: var(--space-2, 8px);
  text-align: center;
  font-size: var(--catalyst-typography-caption-size);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgb(var(--v-theme-on-surface-variant));
}

.cmg__weeks {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  grid-auto-rows: minmax(96px, 1fr);
}

.cmg__cell {
  position: relative;
  /* Own stacking context so a slotted full-cell background layer
     (z-index: -1) paints behind the day number + chips, not the page. */
  isolation: isolate;
  display: flex;
  flex-direction: column;
  gap: var(--space-1, 4px);
  padding: var(--space-1, 4px);
  min-width: 0;
  border-top: 1px solid rgb(var(--v-theme-outline-variant));
  border-left: 1px solid rgb(var(--v-theme-outline-variant));
  cursor: pointer;
  color: rgb(var(--v-theme-on-surface));
  background: rgb(var(--v-theme-surface));
}

/* First column has no left border; first row no top border (grid frame owns it). */
.cmg__cell:nth-child(7n + 1) {
  border-left: none;
}
.cmg__cell:nth-child(-n + 7) {
  border-top: none;
}

.cmg__cell:hover {
  background: rgb(var(--v-theme-surface-variant));
}

.cmg__cell:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}

.cmg__cell--muted {
  color: rgb(var(--v-theme-on-surface-variant));
  background: rgb(var(--v-theme-surface-variant));
  opacity: 0.6;
}

.cmg__daynum {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  align-self: flex-end;
  min-width: 24px;
  height: 24px;
  font-size: var(--catalyst-typography-caption-size);
  font-weight: 500;
}

.cmg__cell--today .cmg__daynum {
  border-radius: 999px;
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  font-weight: 700;
}

.cmg__daybody {
  display: flex;
  flex-direction: column;
  gap: var(--space-1, 4px);
  min-width: 0;
}
</style>
