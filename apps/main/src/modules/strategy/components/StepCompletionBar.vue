<script setup lang="ts">
/**
 * PL-2.5 — the bar above a step that says where it stands and, for USPs, Personas and the Messaging matrix, carries
 * "Mark as complete" / "Reopen" (per market where the step is per market). Concepts shows its AUTOMATIC state and no
 * button. Warnings (under four USPs, under two personas, cells not set) are shown in a confirm and never block; a step
 * with nothing in it is refused: the dialog says there is nothing to mark. A step with no warning is marked at once.
 * Anyone with an agency role may mark and reopen. The server answers with the new steps block; the page takes it.
 */
import type { StrategyCompletionResponse, StrategySummaryResource } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type MarkableStep } from '../api/strategy.api'
import {
  dataCount,
  isMarked,
  liveWarnings,
  markInfo,
  stateFor,
  type StepName,
} from '../completionLogic'
import { useStrategyContext } from '../composables/useStrategyContext'
import { strategyErrorMessage } from '../strategyErrors'

import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  summary: StrategySummaryResource
  step: StepName
  /** The market's ulid for a per-market step; null for USPs. */
  market: string | null
  marketName: string
}>()
const emit = defineEmits<{ completed: [data: StrategyCompletionResponse['data']] }>()

const { t, locale } = useI18n()
const { scope } = useStrategyContext()

const a = computed(() => props.summary.attributes)
const state = computed(() => stateFor(a.value, props.step, props.market))
const markable = computed(() => props.step !== 'concepts')
const marked = computed(() =>
  markable.value ? isMarked(a.value, props.step as MarkableStep, props.market) : false,
)
const info = computed(() =>
  markable.value ? markInfo(a.value, props.step as MarkableStep, props.market) : null,
)
const warnings = computed(() =>
  markable.value ? liveWarnings(a.value, props.step as MarkableStep, props.market) : [],
)
const count = computed(() => dataCount(a.value, props.step, props.market))
const scopeLabel = computed(() =>
  props.step === 'usps'
    ? t('app.strategy.stepper.usps')
    : `${t(`app.strategy.stepper.${props.step}`)} · ${props.marketName}`,
)

const markedLine = computed(() => {
  const i = info.value
  if (i === null) return ''
  const date = new Date(i.at).toLocaleDateString(locale.value, { dateStyle: 'medium' })
  return i.former || i.name === null
    ? t('app.strategy.completion.markedByFormer', { date })
    : t('app.strategy.completion.markedBy', { name: i.name, date })
})

function warningText(w: { code: string; params: Record<string, number> }): string {
  if (w.code === 'usps_under_recommended')
    return t('app.strategy.completion.warnUsps', w.params, w.params.count ?? 0)
  if (w.code === 'personas_under_minimum')
    return t('app.strategy.completion.warnPersonas', w.params, w.params.count ?? 0)
  return t('app.strategy.completion.warnCells', w.params)
}

const busy = ref(false)
const inlineError = ref<string | null>(null)
const dialogOpen = ref(false)
const dialogMode = ref<'warn' | 'nothing'>('warn')
const dialogError = ref<string | null>(null)

async function run(kind: 'mark' | 'reopen'): Promise<boolean> {
  const s = scope.value
  if (s === null || busy.value) return false
  busy.value = true
  inlineError.value = null
  dialogError.value = null
  try {
    const step = props.step as MarkableStep
    const r =
      kind === 'mark'
        ? await strategyApi.markComplete(s, step, props.market)
        : await strategyApi.reopen(s, step, props.market)
    emit('completed', r.data)
    return true
  } catch (e) {
    const msg = strategyErrorMessage(e, t)
    if (dialogOpen.value) dialogError.value = msg
    else inlineError.value = msg
    return false
  } finally {
    busy.value = false
  }
}

async function onMark(): Promise<void> {
  if (count.value === 0) {
    dialogMode.value = 'nothing'
    dialogError.value = null
    dialogOpen.value = true
    return
  }
  if (warnings.value.length > 0) {
    dialogMode.value = 'warn'
    dialogError.value = null
    dialogOpen.value = true
    return
  }
  await run('mark')
}

async function confirmMark(): Promise<void> {
  if (await run('mark')) dialogOpen.value = false
}
</script>

<template>
  <div class="completion-bar" :data-state="state" data-test="completion-bar">
    <div class="completion-bar__state">
      <span
        class="completion-bar__dot"
        :class="`completion-bar__dot--${state}`"
        aria-hidden="true"
      />
      <div class="completion-bar__text">
        <span class="completion-bar__label" data-test="completion-state">{{
          state === 'done'
            ? t('app.strategy.completion.complete')
            : t(`app.strategy.stepper.state.${state}`)
        }}</span>
        <span v-if="!markable" class="completion-bar__sub" data-test="completion-auto">{{
          t('app.strategy.completion.auto')
        }}</span>
        <span
          v-else-if="marked && markedLine"
          class="completion-bar__sub"
          data-test="completion-marked-by"
          >{{ markedLine }}</span
        >
      </div>
    </div>
    <div class="completion-bar__actions">
      <slot name="extra" />
      <v-btn
        v-if="markable && marked"
        variant="outlined"
        size="small"
        :loading="busy"
        data-test="completion-reopen"
        @click="run('reopen')"
        >{{ t('app.strategy.completion.reopen') }}</v-btn
      >
      <v-btn
        v-else-if="markable"
        color="primary"
        variant="flat"
        size="small"
        prepend-icon="mdi-check"
        :loading="busy"
        data-test="completion-mark"
        @click="onMark"
        >{{ t('app.strategy.completion.mark') }}</v-btn
      >
    </div>
    <v-alert
      v-if="inlineError"
      class="completion-bar__error"
      type="error"
      variant="tonal"
      density="compact"
      data-test="completion-error"
    >
      {{ inlineError }}
    </v-alert>

    <StrategyConfirmDialog
      v-model="dialogOpen"
      :title="
        dialogMode === 'nothing'
          ? t('app.strategy.completion.nothingTitle')
          : t('app.strategy.completion.confirmTitle', { scope: scopeLabel })
      "
      :body="
        dialogMode === 'nothing'
          ? t(`app.strategy.completion.nothing.${step}`, { market: marketName })
          : t('app.strategy.completion.confirmBody')
      "
      :confirm-label="t('app.strategy.completion.anyway')"
      :confirm-disabled="dialogMode === 'nothing'"
      confirm-color="primary"
      :busy="busy"
      :error="dialogError"
      test-id="completion-dialog"
      @confirm="confirmMark"
    >
      <ul v-if="dialogMode === 'warn'" class="completion-warnings" data-test="completion-warnings">
        <li v-for="w in warnings" :key="w.code" :data-test="`completion-warning-${w.code}`">
          <v-icon icon="mdi-alert-outline" size="14" aria-hidden="true" />
          {{ warningText(w) }}
        </li>
      </ul>
    </StrategyConfirmDialog>
  </div>
</template>

<style scoped>
.completion-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px 16px;
  padding: 12px 16px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface));
}
.completion-bar__state {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.completion-bar__dot {
  flex: none;
  width: 10px;
  height: 10px;
  border-radius: 9999px;
  border: 1.5px solid rgb(var(--v-theme-on-surface-variant));
}
.completion-bar__dot--in_progress {
  background: rgb(var(--v-theme-warning));
  border-color: rgb(var(--v-theme-warning));
}
.completion-bar__dot--done {
  background: rgb(var(--v-theme-primary));
  border-color: rgb(var(--v-theme-primary));
}
.completion-bar__text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.completion-bar__label {
  font-size: 13px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}
.completion-bar__sub {
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
  overflow-wrap: anywhere;
}
.completion-bar__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.completion-bar__error {
  flex: 1 0 100%;
}
.completion-warnings {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;
  color: rgb(var(--v-theme-warning));
}
</style>
