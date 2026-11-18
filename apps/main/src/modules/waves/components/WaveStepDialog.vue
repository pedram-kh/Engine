<script setup lang="ts">
/**
 * PL-1.3 — add a step or edit one (plan 4.2; no design frame, built from the dialog and field styles).
 *
 * Staff may change status, owner and dates; the name and the stream are shown only to admin and manager (a staff
 * request that carried them would be 403 as a whole). A strategy step can be renamed but not moved. The owner is
 * an active member, the word "Client", or nobody. Only fields that changed are sent.
 *
 * The card is keyed by a counter bumped on every open and close, so a menu left open inside it is unmounted and a
 * fast reopen never shows a stale list behind the dialog (the PL-1.2 D1 lesson).
 */
import { ApiError } from '@catalyst/api-client'
import type {
  WaveStepResource,
  WaveStepStatus,
  WaveStepUpdatePayload,
  WaveStream,
} from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { MENU_ABOVE_DIALOG_Z } from '../constants'
import { STREAM_ORDER } from '../trackerFormat'

export interface MemberOption {
  /** The user ulid the API expects as `owner`. */
  id: string
  name: string
}

const NONE = '__none__'
const CLIENT = '__client__'
const FORMER = '__former__'

const props = defineProps<{
  modelValue: boolean
  /** Null while adding. */
  step: WaveStepResource | null
  /** The stream preselected when adding from a group. */
  defaultStream: WaveStream | null
  members: MemberOption[]
  /** The streams the wave has now: a step is added to, or moved to, one of these. */
  streams: WaveStream[]
  canManage: boolean
  /** Sends the payload; rejects with the API error so the dialog can show its message. */
  save: (payload: WaveStepUpdatePayload) => Promise<void>
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t, te } = useI18n()

const menuAboveDialog = {
  zIndex: MENU_ABOVE_DIALOG_Z,
  maxHeight: 'calc(100dvh - 24px)',
  contentClass: 'wave-dialog-menu',
}

const session = ref(0)
const isEdit = computed(() => props.step !== null)
const isStrategy = computed(() => props.step?.attributes.is_strategy === true)

const name = ref('')
const stream = ref<WaveStream>('ugc_video')
const owner = ref<string>(NONE)
const status = ref<WaveStepStatus>('not_started')
const startsOn = ref('')
const dueOn = ref('')
const submitted = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)

const initialOwner = ref<string>(NONE)

function ownerKeyOf(step: WaveStepResource | null): string {
  if (step === null) return NONE
  const a = step.attributes
  if (a.owner !== null) return a.owner.id
  if (a.owner_client) return CLIENT
  return a.owner_is_former ? FORMER : NONE
}

watch(
  () => props.modelValue,
  (open) => {
    session.value += 1
    if (!open) return
    submitted.value = false
    saving.value = false
    formError.value = null
    const s = props.step
    name.value = s?.attributes.name ?? ''
    stream.value = s?.attributes.stream ?? props.defaultStream ?? 'ugc_video'
    initialOwner.value = ownerKeyOf(s)
    owner.value = initialOwner.value
    status.value = s?.attributes.status ?? 'not_started'
    startsOn.value = s?.attributes.starts_on ?? ''
    dueOn.value = s?.attributes.due_on ?? ''
  },
  { immediate: true },
)

const ownerItems = computed(() => {
  const items: { title: string; value: string; props?: { disabled: boolean } }[] = [
    { title: t('app.waves.tracker.owner.unassigned'), value: NONE },
    { title: t('app.waves.tracker.owner.client'), value: CLIENT },
  ]
  if (initialOwner.value === FORMER) {
    items.push({
      title: t('app.waves.tracker.owner.former'),
      value: FORMER,
      props: { disabled: true },
    })
  }
  for (const m of props.members) items.push({ title: m.name, value: m.id })
  return items
})

const statusItems = computed(() =>
  (['not_started', 'in_progress', 'done'] as const).map((value) => ({
    title: t(`app.waves.tracker.stepStatus.${value}`),
    value,
  })),
)

const streamItems = computed(() =>
  STREAM_ORDER.filter((value) => props.streams.includes(value)).map((value) => ({
    title: t(`app.waves.tracker.streams.${value}`),
    value,
  })),
)

const showName = computed(() => props.canManage)
const showStream = computed(() => props.canManage && !isStrategy.value)
const nameError = computed(() =>
  submitted.value && showName.value && name.value.trim() === ''
    ? t('app.waves.tracker.validation.nameRequired')
    : null,
)
const datesError = computed(() =>
  startsOn.value !== '' && dueOn.value !== '' && dueOn.value < startsOn.value
    ? t('app.waves.tracker.errors.dates_invalid')
    : null,
)

const CODE_KEYS: Record<string, string> = {
  'step.owner_not_a_member': 'ownerNotMember',
  'step.owner_conflict': 'ownerConflict',
  'step.strategy_locked': 'strategyLocked',
  'step.dates_invalid': 'dates_invalid',
  'wave.brand_archived': 'brandArchived',
}

function messageFor(err: unknown): string {
  if (err instanceof ApiError) {
    const key = CODE_KEYS[err.code]
    if (key !== undefined && te(`app.waves.tracker.errors.${key}`)) {
      return t(`app.waves.tracker.errors.${key}`)
    }
    if (err.status === 403) return t('app.waves.tracker.errors.forbidden')
  }
  return t('app.waves.tracker.errors.saveFailed')
}

function close(): void {
  if (saving.value) return
  emit('update:modelValue', false)
}

function payload(): WaveStepUpdatePayload {
  const out: WaveStepUpdatePayload = {}
  const s = props.step
  if (showName.value && (s === null || name.value.trim() !== s.attributes.name)) {
    out.name = name.value.trim()
  }
  if (showStream.value && (s === null || stream.value !== s.attributes.stream)) {
    out.stream = stream.value
  }
  if (owner.value !== initialOwner.value) {
    if (owner.value === CLIENT) {
      out.owner = null
      out.owner_client = true
    } else if (owner.value === NONE) {
      out.owner = null
      out.owner_client = false
    } else {
      out.owner = owner.value
      out.owner_client = false
    }
  }
  if (s !== null && status.value !== s.attributes.status) out.status = status.value
  if (s === null || startsOn.value !== (s.attributes.starts_on ?? '')) {
    out.starts_on = startsOn.value === '' ? null : startsOn.value
  }
  if (s === null || dueOn.value !== (s.attributes.due_on ?? '')) {
    out.due_on = dueOn.value === '' ? null : dueOn.value
  }
  return out
}

async function submit(): Promise<void> {
  submitted.value = true
  formError.value = null
  if (nameError.value !== null || datesError.value !== null) return
  saving.value = true
  try {
    await props.save(payload())
    saving.value = false
    emit('update:modelValue', false)
  } catch (err) {
    saving.value = false
    formError.value = messageFor(err)
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    persistent
    data-test="step-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!saving) emit('update:modelValue', v)
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 pt-4 px-6">
        {{
          isEdit ? t('app.waves.tracker.dialog.editTitle') : t('app.waves.tracker.dialog.addTitle')
        }}
      </v-card-title>
      <v-card-text class="d-flex flex-column ga-4 px-6">
        <v-text-field
          v-if="showName"
          v-model="name"
          :label="t('app.waves.tracker.dialog.name')"
          variant="outlined"
          density="comfortable"
          maxlength="160"
          hide-details="auto"
          :error-messages="nameError ? [nameError] : []"
          data-test="step-name-input"
        />
        <div v-else class="text-subtitle-1" data-test="step-name-readonly">
          {{ step?.attributes.name }}
        </div>
        <v-select
          v-if="showStream"
          v-model="stream"
          :items="streamItems"
          item-title="title"
          item-value="value"
          :label="t('app.waves.tracker.dialog.stream')"
          variant="outlined"
          density="comfortable"
          hide-details
          :menu-props="menuAboveDialog"
          data-test="step-stream-select"
        />
        <v-select
          v-model="owner"
          :items="ownerItems"
          item-title="title"
          item-value="value"
          :label="t('app.waves.tracker.dialog.owner')"
          variant="outlined"
          density="comfortable"
          hide-details
          :menu-props="menuAboveDialog"
          data-test="step-owner-select"
        />
        <v-select
          v-if="isEdit"
          v-model="status"
          :items="statusItems"
          item-title="title"
          item-value="value"
          :label="t('app.waves.tracker.dialog.status')"
          variant="outlined"
          density="comfortable"
          hide-details
          :menu-props="menuAboveDialog"
          data-test="step-status-select"
        />
        <div class="d-flex ga-3 step-dialog__dates">
          <v-text-field
            v-model="startsOn"
            type="date"
            :label="t('app.waves.tracker.dialog.startsOn')"
            variant="outlined"
            density="comfortable"
            hide-details="auto"
            data-test="step-starts-on"
          />
          <v-text-field
            v-model="dueOn"
            type="date"
            :label="t('app.waves.tracker.dialog.dueOn')"
            variant="outlined"
            density="comfortable"
            hide-details="auto"
            :error-messages="datesError ? [datesError] : []"
            data-test="step-due-on"
          />
        </div>
        <v-alert
          v-if="formError"
          type="error"
          variant="tonal"
          density="compact"
          data-test="step-form-error"
        >
          {{ formError }}
        </v-alert>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="step-cancel" @click="close">
          {{ t('app.waves.tracker.dialog.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="step-save"
          @click="submit"
        >
          {{ isEdit ? t('app.waves.tracker.dialog.save') : t('app.waves.tracker.dialog.add') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
@media (max-width: 480px) {
  .step-dialog__dates {
    flex-direction: column;
  }
}
</style>
