<script setup lang="ts">
/**
 * PL-1.3 — the tracker of one wave: stream cards, the steps panel and every dialog. It owns the data and reports
 * each fresh tracker to the page (`tracker`) so the page can draw the health pill and the progress bar.
 *
 * The first load provisions the default steps on the server. A done check is optimistic: it is reverted with an
 * inline message when the save fails, and a 404 (someone removed the step) shows "This step was removed" and
 * reloads. After every successful write the tracker is read again, so the cards, the figures and the bar all come
 * from the server and the web holds no copy of the rules.
 */
import { ApiError } from '@catalyst/api-client'
import type {
  WaveStepResource,
  WaveStepUpdatePayload,
  WaveStream,
  WaveTrackerResource,
} from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { membersApi } from '@/modules/agency-users/api/members.api'
import { wavesApi } from '../api/waves.api'
import WaveConfirmDialog from './WaveConfirmDialog.vue'
import WaveStepDialog, { type MemberOption } from './WaveStepDialog.vue'
import WaveStepsPanel from './WaveStepsPanel.vue'
import WaveStreamCard from './WaveStreamCard.vue'

const props = defineProps<{
  agencyId: string
  brandId: string
  waveId: string
  canManage: boolean
  meUlid: string | null
}>()

const emit = defineEmits<{ tracker: [value: WaveTrackerResource | null] }>()

const { t } = useI18n()

const tracker = ref<WaveTrackerResource | null>(null)
const loading = ref(true)
const failed = ref(false)
const busy = ref<Set<string>>(new Set())
const rowErrors = ref<Record<string, string>>({})
const members = ref<MemberOption[]>([])
const snackbar = ref(false)

const stepDialog = ref(false)
const editing = ref<WaveStepResource | null>(null)
const defaultStream = ref<WaveStream | null>(null)

type Confirm =
  | { kind: 'step'; step: WaveStepResource }
  | { kind: 'stream'; stream: WaveStream; count: number }
const confirm = ref<Confirm | null>(null)
const confirmOpen = ref(false)
const confirmBusy = ref(false)
const confirmError = ref<string | null>(null)

const streams = computed(() => tracker.value?.attributes.streams ?? [])
const presentStreams = computed(() => streams.value.map((s) => s.stream))

function apply(next: WaveTrackerResource): void {
  tracker.value = next
  emit('tracker', next)
}

async function load(silent = false): Promise<void> {
  if (!silent) {
    loading.value = true
    failed.value = false
  }
  try {
    apply((await wavesApi.tracker(props.agencyId, props.brandId, props.waveId)).data)
    failed.value = false
  } catch {
    if (!silent || tracker.value === null) failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadMembers(): Promise<void> {
  try {
    const res = await membersApi.list(props.agencyId, { per_page: 100, sort: 'name', order: 'asc' })
    members.value = res.data
      .filter((m) => m.attributes.status === 'active')
      .map((m) => ({ id: m.attributes.user_id, name: m.attributes.name }))
  } catch {
    members.value = []
  }
}

onMounted(async () => {
  await load()
  void loadMembers()
})

function isNotFound(err: unknown): boolean {
  return err instanceof ApiError && err.status === 404
}

async function removedByOther(): Promise<void> {
  snackbar.value = true
  await load(true)
}

function setBusy(id: string, on: boolean): void {
  const next = new Set(busy.value)
  if (on) next.add(id)
  else next.delete(id)
  busy.value = next
}

async function toggle(step: WaveStepResource): Promise<void> {
  const current = tracker.value
  if (current === null || busy.value.has(step.id)) return
  const markDone = step.attributes.status !== 'done'
  const snapshot = JSON.parse(JSON.stringify(current)) as WaveTrackerResource
  const target = current.attributes.steps.find((s) => s.id === step.id)
  if (target === undefined) return
  target.attributes.status = markDone ? 'done' : 'not_started'
  target.attributes.display_status = markDone ? 'done' : 'up_next'
  const rest = { ...rowErrors.value }
  delete rest[step.id]
  rowErrors.value = rest
  setBusy(step.id, true)
  try {
    await wavesApi.updateStep(props.agencyId, props.brandId, props.waveId, step.id, {
      status: markDone ? 'done' : 'not_started',
    })
    await load(true)
  } catch (err) {
    if (isNotFound(err)) {
      await removedByOther()
    } else {
      apply(snapshot)
      rowErrors.value = { ...rowErrors.value, [step.id]: t('app.waves.tracker.errors.saveFailed') }
    }
  } finally {
    setBusy(step.id, false)
  }
}

function openEdit(step: WaveStepResource): void {
  editing.value = step
  defaultStream.value = null
  stepDialog.value = true
}

function openAdd(stream: WaveStream | null): void {
  editing.value = null
  defaultStream.value = stream ?? presentStreams.value[0] ?? null
  stepDialog.value = true
}

async function saveStep(payload: WaveStepUpdatePayload): Promise<void> {
  const step = editing.value
  try {
    if (step === null) {
      await wavesApi.addStep(props.agencyId, props.brandId, props.waveId, {
        stream: payload.stream ?? defaultStream.value ?? 'ugc_video',
        name: payload.name ?? '',
        ...payload,
      })
    } else {
      await wavesApi.updateStep(props.agencyId, props.brandId, props.waveId, step.id, payload)
    }
  } catch (err) {
    if (step !== null && isNotFound(err)) {
      await removedByOther()
      return
    }
    throw err
  }
  await load(true)
}

function askRemoveStep(step: WaveStepResource): void {
  confirm.value = { kind: 'step', step }
  confirmError.value = null
  confirmOpen.value = true
}

function askRemoveStream(stream: WaveStream): void {
  const count = streams.value.find((s) => s.stream === stream)?.total ?? 0
  confirm.value = { kind: 'stream', stream, count }
  confirmError.value = null
  confirmOpen.value = true
}

const confirmTitle = computed(() =>
  confirm.value?.kind === 'stream'
    ? t('app.waves.tracker.removeStream.title')
    : t('app.waves.tracker.remove.title'),
)
const confirmBody = computed(() => {
  const c = confirm.value
  if (c === null) return ''
  if (c.kind === 'step') return t('app.waves.tracker.remove.body', { name: c.step.attributes.name })
  return t(
    'app.waves.tracker.removeStream.body',
    { stream: t(`app.waves.tracker.streams.${c.stream}`), count: c.count },
    c.count,
  )
})
const confirmLabel = computed(() =>
  confirm.value?.kind === 'stream'
    ? t('app.waves.tracker.removeStream.confirm')
    : t('app.waves.tracker.remove.confirm'),
)

function errorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    const keys: Record<string, string> = {
      'step.strategy_locked': 'strategyLocked',
      'stream.not_removable': 'streamNotRemovable',
      'wave.brand_archived': 'brandArchived',
    }
    const key = keys[err.code]
    if (key !== undefined) return t(`app.waves.tracker.errors.${key}`)
    if (err.status === 403) return t('app.waves.tracker.errors.forbidden')
  }
  return t('app.waves.tracker.errors.saveFailed')
}

async function doConfirm(): Promise<void> {
  const c = confirm.value
  if (c === null) return
  confirmBusy.value = true
  confirmError.value = null
  try {
    if (c.kind === 'step') {
      await wavesApi.removeStep(props.agencyId, props.brandId, props.waveId, c.step.id)
      await load(true)
    } else {
      apply(
        (await wavesApi.removeStream(props.agencyId, props.brandId, props.waveId, c.stream)).data,
      )
    }
    confirmOpen.value = false
  } catch (err) {
    if (c.kind === 'step' && isNotFound(err)) {
      confirmOpen.value = false
      await removedByOther()
    } else if (c.kind === 'stream' && isNotFound(err)) {
      confirmOpen.value = false
      await load(true)
    } else {
      confirmError.value = errorMessage(err)
    }
  } finally {
    confirmBusy.value = false
  }
}

async function addStream(stream: WaveStream): Promise<void> {
  try {
    apply((await wavesApi.addStream(props.agencyId, props.brandId, props.waveId, stream)).data)
  } catch (err) {
    rowErrors.value = { ...rowErrors.value, __stream__: errorMessage(err) }
  }
}

const streamError = computed(() => rowErrors.value.__stream__ ?? null)

defineExpose({ reload: () => load(true) })
</script>

<template>
  <div class="tracker" data-test="wave-tracker">
    <v-skeleton-loader v-if="loading" type="article" data-test="tracker-skeleton" />
    <v-alert v-else-if="failed" type="error" variant="tonal" data-test="tracker-error">
      {{ t('app.waves.tracker.errors.loadFailed') }}
      <template #append>
        <v-btn variant="outlined" size="small" data-test="tracker-retry" @click="load()">
          {{ t('app.waves.tracker.errors.retry') }}
        </v-btn>
      </template>
    </v-alert>

    <template v-else-if="tracker">
      <div class="tracker__cards" data-test="stream-cards">
        <WaveStreamCard
          v-for="s in tracker.attributes.streams"
          :key="s.stream"
          :summary="s"
          :today="tracker.attributes.today"
        />
        <p
          v-if="tracker.attributes.streams.length === 0"
          class="tracker__none"
          data-test="tracker-no-steps"
        >
          {{ t('app.waves.tracker.card.noSteps') }}
        </p>
      </div>

      <WaveStepsPanel
        :tracker="tracker"
        :can-manage="canManage"
        :me-ulid="meUlid"
        :busy="busy"
        :row-errors="rowErrors"
        @toggle="toggle"
        @edit="openEdit"
        @remove="askRemoveStep"
        @add="openAdd"
        @remove-stream="askRemoveStream"
        @add-stream="addStream"
      />
      <v-alert
        v-if="streamError"
        type="error"
        variant="tonal"
        density="compact"
        data-test="stream-error"
      >
        {{ streamError }}
      </v-alert>
    </template>

    <WaveStepDialog
      v-model="stepDialog"
      :step="editing"
      :default-stream="defaultStream"
      :members="members"
      :streams="presentStreams"
      :can-manage="canManage"
      :save="saveStep"
    />
    <WaveConfirmDialog
      v-model="confirmOpen"
      :title="confirmTitle"
      :body="confirmBody"
      :confirm-label="confirmLabel"
      :busy="confirmBusy"
      :error="confirmError"
      @confirm="doConfirm"
    />
    <v-snackbar v-model="snackbar" :timeout="5000" data-test="step-removed-snackbar">
      {{ t('app.waves.tracker.removedByOther') }}
    </v-snackbar>
  </div>
</template>

<style scoped>
.tracker {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.tracker__cards {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

.tracker__none {
  margin: 0;
  color: rgb(var(--v-theme-text-tertiary));
}

/* 768 to 1279: one card per row. Below 768 the same, with the panel's own stacked rows. */
@media (max-width: 1279px) {
  .tracker__cards {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
