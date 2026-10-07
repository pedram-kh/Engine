/**
 * useAvailabilityMonth — the creator availability month's data + actions,
 * shared by the web calendar and the creator app's compact grid + agenda
 * (AH-100: moved verbatim out of `AvailabilityCalendar.vue` so both shells
 * render the same state).
 *
 *   - Fetches occurrences for the visible month from
 *     `GET /creators/me/availability` (the api wrapper), reading the
 *     EFFECTIVE range from `meta.window` (D-b6 — the requested span is
 *     silently clamped at 366d).
 *   - Buckets in the creator's RESOLVED timezone (D-b7): a UTC instant lands
 *     in the correct day for that tz; a null user tz falls back to the
 *     browser tz.
 *   - ⚠ Entries are keyed `id + starts_at` (D-b5) — every occurrence of a
 *     recurring block shares the block ULID, so an `id`-only key would
 *     collide. The composite key is per-day-unique.
 *   - Multi-day blocks land on each covered day (end-exclusive at midnight).
 *   - Owns the create/edit dialog state: a day opens create, a block opens
 *     edit of its series.
 *
 * Must be called from a component `setup` (it uses `useI18n` and registers
 * the month watcher).
 */

import type { AvailabilityOccurrenceResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { availabilityApi } from './availability.api'
import {
  eachDayKey,
  monthLabel,
  monthQueryWindow,
  todayKey,
  weekdayLabels,
  zonedTime,
} from './datetime'
import { useResolvedTimezone } from './useResolvedTimezone'

/** One availability occurrence with its composite day key (D-b5). */
export interface DayEntry {
  /** Composite key `id|starts_at`. */
  key: string
  occurrence: AvailabilityOccurrenceResource
}

export function useAvailabilityMonth() {
  const { t, locale } = useI18n()
  const zone = useResolvedTimezone()

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

  /**
   * `'YYYY-MM-DD'` → the entries covering it, split into all-day vs timed.
   * Every covered day of a multi-day block gets an entry (end-exclusive at
   * midnight), so a multi-day all-day block tints each spanned cell.
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

  return {
    zone,
    year,
    month,
    occurrences,
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
  }
}
