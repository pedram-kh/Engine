<script setup lang="ts">
/**
 * AvailabilityBlockDialog — create / edit / delete a creator availability
 * block (Sprint 5 Chunk B, D-b8…D-b12).
 *
 * Mirrors the established main-SPA dialog pattern (InviteUserModal /
 * BrandForm): `v-dialog` + `v-card` + a `<form novalidate @submit.prevent>`,
 * with `extractFieldErrors` → per-field `:error-messages` and a generic
 * banner fallback for non-field failures (D-b9).
 *
 * ⚠ Series-level editing (D-b8). The backend supports block-level
 * update/delete ONLY — there is no per-occurrence exception path. Clicking
 * ANY occurrence of a recurring block edits/deletes the WHOLE series. The
 * dialog states this plainly (the series notice) so a creator never thinks
 * they are editing just one day. Update is a FULL-RESOURCE REPLACE.
 *
 * ⚠ UNTIL-instant (D-b12). The recurrence "ends on" date emits an UNTIL at
 * end-of-day in the creator's tz (see `recurrence.untilInstant`).
 *
 * All timezone conversion happens here (UTC ↔ resolved tz); the child
 * `DateTimeField` is tz-agnostic.
 */

import {
  ApiError,
  extractFieldErrors,
  type AvailabilityBlockType,
  type AvailabilityOccurrenceResource,
  type CreateAvailabilityBlockPayload,
  type CreatorSettableKind,
} from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { availabilityApi } from '../availability.api'
import { addDays, eachDayKey, todayKey, utcIsoToZoned, zonedToUtcIso } from '../datetime'
import { BLOCK_TYPE_VALUES, KIND_VALUES } from '../options'
import { buildWeeklyRule, parseWeeklyRule, untilToDate, WEEKDAY_CODES } from '../recurrence'
import DateTimeField from './DateTimeField.vue'

const props = defineProps<{
  modelValue: boolean
  /** null = create; a resource = edit/delete (series-level). */
  occurrence: AvailabilityOccurrenceResource | null
  /** `'YYYY-MM-DD'` seed when opened by clicking a day (create only). */
  initialDate?: string | null
  /** Creator's resolved timezone (D-b7). */
  zone: string
}>()

const emit = defineEmits<{
  'update:modelValue': [open: boolean]
  saved: []
  deleted: []
}>()

const { t } = useI18n()

type AvailabilityField =
  | 'starts_at'
  | 'ends_at'
  | 'is_all_day'
  | 'block_type'
  | 'kind'
  | 'reason'
  | 'is_recurring'
  | 'recurrence_rule'

// ─── Form state ──────────────────────────────────────────────────────────
const startDate = ref('')
const startTime = ref('09:00')
const endDate = ref('')
const endTime = ref('10:00')
const isAllDay = ref(false)
const blockType = ref<AvailabilityBlockType>('hard')
const kind = ref<CreatorSettableKind>('vacation')
const reason = ref('')
const isRecurring = ref(false)
const interval = ref(1)
const byday = ref<string[]>([])
const hasEndDate = ref(false)
const endsOn = ref<string | null>(null)

const submitting = ref(false)
const deleting = ref(false)
const confirmingDelete = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<AvailabilityField, readonly string[]>>>({})

const isEdit = computed(() => props.occurrence !== null)
const isRecurringSeries = computed(() => props.occurrence?.attributes.is_recurring === true)

const blockTypeOptions = computed(() =>
  BLOCK_TYPE_VALUES.map((value) => ({ title: t(`availability.blockType.${value}`), value })),
)
const kindOptions = computed(() =>
  KIND_VALUES.map((value) => ({ title: t(`availability.kind.${value}`), value })),
)

function resetForm(): void {
  error.value = null
  fieldErrors.value = {}
  confirmingDelete.value = false

  const occurrence = props.occurrence
  if (occurrence !== null) {
    const attrs = occurrence.attributes
    const start = utcIsoToZoned(attrs.starts_at, props.zone)
    startDate.value = start.date
    startTime.value = start.time
    isAllDay.value = attrs.is_all_day

    if (attrs.is_all_day) {
      // We store an all-day block as `D 00:00 → (last+1) 00:00`; the last
      // covered day key is the end date the creator picked.
      const days = eachDayKey(attrs.starts_at, attrs.ends_at, props.zone)
      endDate.value = days[days.length - 1] ?? start.date
    } else {
      const end = utcIsoToZoned(attrs.ends_at, props.zone)
      endDate.value = end.date
      endTime.value = end.time
    }

    blockType.value = attrs.block_type
    // `assignment_auto` is not creator-settable; never reachable via the
    // dialog, but guard the cast defensively.
    kind.value = (KIND_VALUES as readonly string[]).includes(attrs.kind)
      ? (attrs.kind as CreatorSettableKind)
      : 'other'
    reason.value = attrs.reason ?? ''
    isRecurring.value = attrs.is_recurring

    if (attrs.is_recurring && attrs.recurrence_rule !== null) {
      const parsed = parseWeeklyRule(attrs.recurrence_rule)
      interval.value = parsed.interval
      byday.value = [...parsed.byday]
      const until = parsed.until !== null ? untilToDate(parsed.until, props.zone) : null
      hasEndDate.value = until !== null
      endsOn.value = until
    } else {
      interval.value = 1
      byday.value = []
      hasEndDate.value = false
      endsOn.value = null
    }
    return
  }

  // Create defaults.
  const seed = props.initialDate ?? todayKey(props.zone)
  startDate.value = seed
  endDate.value = seed
  startTime.value = '09:00'
  endTime.value = '10:00'
  isAllDay.value = false
  blockType.value = 'hard'
  kind.value = 'vacation'
  reason.value = ''
  isRecurring.value = false
  interval.value = 1
  byday.value = []
  hasEndDate.value = false
  endsOn.value = null
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      resetForm()
    }
  },
  { immediate: true },
)

watch(hasEndDate, (on) => {
  if (on && endsOn.value === null) {
    endsOn.value = addDays(startDate.value, 28)
  } else if (!on) {
    endsOn.value = null
  }
})

function close(): void {
  emit('update:modelValue', false)
}

function buildPayload(): CreateAvailabilityBlockPayload {
  let startsAtIso: string
  let endsAtIso: string

  if (isAllDay.value) {
    startsAtIso = zonedToUtcIso(startDate.value, '00:00', props.zone)
    // End-exclusive next-day midnight so a single all-day block covers
    // exactly its day(s) (matches `eachDayKey`'s end-exclusive rule).
    endsAtIso = zonedToUtcIso(addDays(endDate.value, 1), '00:00', props.zone)
  } else {
    startsAtIso = zonedToUtcIso(startDate.value, startTime.value, props.zone)
    endsAtIso = zonedToUtcIso(endDate.value, endTime.value, props.zone)
  }

  const payload: CreateAvailabilityBlockPayload = {
    starts_at: startsAtIso,
    ends_at: endsAtIso,
    is_all_day: isAllDay.value,
    block_type: blockType.value,
    kind: kind.value,
    reason: reason.value.trim() === '' ? null : reason.value.trim(),
    is_recurring: isRecurring.value,
  }

  if (isRecurring.value) {
    payload.recurrence_rule = buildWeeklyRule({
      interval: interval.value,
      byday: byday.value,
      endsOn: hasEndDate.value ? endsOn.value : null,
      zone: props.zone,
    })
  }

  return payload
}

async function onSubmit(): Promise<void> {
  submitting.value = true
  error.value = null
  fieldErrors.value = {}

  try {
    const payload = buildPayload()
    if (props.occurrence !== null) {
      await availabilityApi.update(props.occurrence.id, payload)
    } else {
      await availabilityApi.create(payload)
    }
    emit('saved')
    close()
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<AvailabilityField>(err)
    }
    if (Object.keys(fieldErrors.value).length === 0) {
      error.value = t('availability.dialog.errors.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

async function onDelete(): Promise<void> {
  if (props.occurrence === null) {
    return
  }
  if (!confirmingDelete.value) {
    confirmingDelete.value = true
    return
  }

  deleting.value = true
  error.value = null
  try {
    await availabilityApi.delete(props.occurrence.id)
    emit('deleted')
    close()
  } catch {
    error.value = t('availability.dialog.errors.deleteFailed')
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="560"
    scrollable
    data-test="availability-dialog"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <v-card>
      <v-card-title class="text-h6 pa-4" data-test="availability-dialog-title">
        {{ isEdit ? t('availability.dialog.editTitle') : t('availability.dialog.createTitle') }}
      </v-card-title>

      <v-card-text>
        <!-- Series-level edit notice (D-b8): editing/deleting any occurrence
             of a recurring block affects the whole series. -->
        <v-alert
          v-if="isEdit && isRecurringSeries"
          type="info"
          variant="tonal"
          density="comfortable"
          class="mb-4"
          data-test="availability-series-notice"
        >
          {{ t('availability.dialog.seriesNotice') }}
        </v-alert>

        <form novalidate data-test="availability-form" @submit.prevent="onSubmit">
          <v-switch
            :model-value="isAllDay"
            :label="t('availability.dialog.fields.allDay')"
            color="primary"
            density="compact"
            hide-details
            class="mb-2"
            data-test="availability-all-day"
            @update:model-value="isAllDay = $event === true"
          />

          <DateTimeField
            :date="startDate"
            :time="startTime"
            :show-time="!isAllDay"
            :date-label="t('availability.dialog.fields.startDate')"
            :time-label="t('availability.dialog.fields.startTime')"
            :date-errors="fieldErrors.starts_at"
            data-test-prefix="availability-start"
            @update:date="startDate = $event"
            @update:time="startTime = $event"
          />

          <DateTimeField
            :date="endDate"
            :time="endTime"
            :show-time="!isAllDay"
            :date-label="t('availability.dialog.fields.endDate')"
            :time-label="t('availability.dialog.fields.endTime')"
            :date-errors="fieldErrors.ends_at"
            data-test-prefix="availability-end"
            @update:date="endDate = $event"
            @update:time="endTime = $event"
          />

          <v-select
            :model-value="blockType"
            :items="blockTypeOptions"
            :label="t('availability.dialog.fields.blockType')"
            :error-messages="fieldErrors.block_type as string[]"
            item-title="title"
            item-value="value"
            :hint="t('availability.dialog.blockTypeHint')"
            persistent-hint
            data-test="availability-block-type"
            @update:model-value="blockType = $event"
          />

          <v-select
            :model-value="kind"
            :items="kindOptions"
            :label="t('availability.dialog.fields.kind')"
            :error-messages="fieldErrors.kind as string[]"
            item-title="title"
            item-value="value"
            class="mt-2"
            data-test="availability-kind"
            @update:model-value="kind = $event"
          />

          <v-text-field
            :model-value="reason"
            :label="t('availability.dialog.fields.reason')"
            :error-messages="fieldErrors.reason as string[]"
            maxlength="255"
            counter="255"
            class="mt-2"
            data-test="availability-reason"
            @update:model-value="reason = $event"
          />

          <v-divider class="my-3" />

          <v-switch
            :model-value="isRecurring"
            :label="t('availability.dialog.fields.recurring')"
            color="primary"
            density="compact"
            hide-details
            data-test="availability-recurring"
            @update:model-value="isRecurring = $event === true"
          />

          <div v-if="isRecurring" data-test="availability-recurrence-builder">
            <v-text-field
              :model-value="interval"
              :label="t('availability.dialog.recurrence.interval')"
              type="number"
              min="1"
              density="comfortable"
              class="mt-2"
              data-test="availability-interval"
              @update:model-value="interval = Math.max(1, Number($event) || 1)"
            />

            <p class="text-body-2 text-medium-emphasis mb-1">
              {{ t('availability.dialog.recurrence.weekdays') }}
            </p>
            <div class="d-flex flex-wrap" data-test="availability-weekdays">
              <v-checkbox
                v-for="code in WEEKDAY_CODES"
                :key="code"
                v-model="byday"
                :value="code"
                :label="t(`availability.weekday.${code}`)"
                density="compact"
                hide-details
                class="mr-3"
                :data-test="`availability-weekday-${code}`"
              />
            </div>

            <v-switch
              :model-value="hasEndDate"
              :label="t('availability.dialog.recurrence.hasEnd')"
              color="primary"
              density="compact"
              hide-details
              class="mt-2"
              data-test="availability-has-end"
              @update:model-value="hasEndDate = $event === true"
            />

            <DateTimeField
              v-if="hasEndDate"
              :date="endsOn ?? ''"
              :show-time="false"
              :date-label="t('availability.dialog.recurrence.endsOn')"
              data-test-prefix="availability-ends-on"
              @update:date="endsOn = $event"
            />

            <p
              v-if="fieldErrors.recurrence_rule"
              class="text-error text-body-2"
              data-test="availability-recurrence-error"
            >
              {{ (fieldErrors.recurrence_rule as string[]).join(' ') }}
            </p>
          </div>

          <div
            v-if="error"
            role="alert"
            aria-live="polite"
            class="text-error text-body-2 mt-3"
            data-test="availability-dialog-error"
          >
            {{ error }}
          </div>
        </form>
      </v-card-text>

      <v-card-actions class="px-4 pb-4">
        <v-btn
          v-if="isEdit"
          color="error"
          variant="text"
          :loading="deleting"
          :disabled="submitting"
          data-test="availability-delete"
          @click="onDelete"
        >
          {{
            confirmingDelete
              ? t('availability.dialog.actions.confirmDelete')
              : t('availability.dialog.actions.delete')
          }}
        </v-btn>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="submitting || deleting"
          data-test="availability-cancel"
          @click="close"
        >
          {{ t('availability.dialog.actions.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="submitting"
          :disabled="submitting || deleting || startDate === ''"
          data-test="availability-submit"
          @click="onSubmit"
        >
          {{ t('availability.dialog.actions.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
