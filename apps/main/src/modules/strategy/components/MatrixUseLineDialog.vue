<script setup lang="ts">
/**
 * PL-2.2 ruling change R2 — "Use the matrix line". The confirmation shows BOTH texts (the manually added angle and the
 * cell's message line) before the angle becomes the synced one. Built from the module's confirm dialog (no frame). The
 * texts are drawn with `{{ }}`, never as HTML. The server converts in one transaction and answers 200 again if it was
 * already done, so a second press is harmless; an error stays in the dialog.
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyErrorMessage } from '../strategyErrors'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  modelValue: boolean
  /** What the dialog is about: "{usp} · {persona}". */
  heading: string
  manualText: string
  matrixLine: string
  /** Resolves when the server answered; throws the API error. */
  apply: () => Promise<void>
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; done: [] }>()

const { t } = useI18n()
const busy = ref(false)
const error = ref<string | null>(null)

watch(
  () => props.modelValue,
  () => {
    busy.value = false
    error.value = null
  },
)

async function confirm(): Promise<void> {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    await props.apply()
    emit('update:modelValue', false)
    emit('done')
  } catch (err) {
    error.value = strategyErrorMessage(err, t)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <StrategyConfirmDialog
    :model-value="modelValue"
    :title="t('app.strategy.matrix.useLineDialog.title')"
    :body="heading"
    :confirm-label="t('app.strategy.matrix.useLine')"
    confirm-color="primary"
    :busy="busy"
    :error="error"
    test-id="matrix-use-line-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
    @confirm="confirm"
  >
    <div class="use-line" data-test="use-line-texts">
      <section class="use-line__block">
        <h4 class="use-line__label">{{ t('app.strategy.matrix.useLineDialog.manual') }}</h4>
        <p class="use-line__text" data-test="use-line-manual">{{ manualText }}</p>
      </section>
      <section class="use-line__block">
        <h4 class="use-line__label">{{ t('app.strategy.matrix.useLineDialog.matrix') }}</h4>
        <p class="use-line__text" data-test="use-line-matrix">{{ matrixLine }}</p>
      </section>
      <p class="use-line__effect">{{ t('app.strategy.matrix.useLineDialog.effect') }}</p>
    </div>
  </StrategyConfirmDialog>
</template>

<style scoped>
.use-line {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.use-line__block {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 12px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface-bright));
}
.use-line__label {
  margin: 0;
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.use-line__text {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: rgb(var(--v-theme-on-surface));
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.use-line__effect {
  margin: 0;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
