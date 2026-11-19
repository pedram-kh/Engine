<script setup lang="ts">
/**
 * PL-2.1 — a short list of lines typed by hand (a persona's drivers, goals, self-talk and barriers): one text
 * field per item, a remove button, an add button and a counter "3 of 6". The add button is off at the maximum, so a
 * seventh item cannot be started. Blank items are dropped by the dialog on Save.
 */
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  modelValue: string[]
  label: string
  max: number
  maxLength: number
  testId: string
  submitted: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()
const { t } = useI18n()

function setAt(i: number, value: string): void {
  const next = [...props.modelValue]
  next[i] = value
  emit('update:modelValue', next)
}
function removeAt(i: number): void {
  emit(
    'update:modelValue',
    props.modelValue.filter((_, idx) => idx !== i),
  )
}
function add(): void {
  if (props.modelValue.length >= props.max) return
  emit('update:modelValue', [...props.modelValue, ''])
}
function lineError(v: string): string[] {
  return props.submitted && Array.from(v.trim()).length > props.maxLength
    ? [t('app.strategy.validation.tooLong', { max: props.maxLength })]
    : []
}
</script>

<template>
  <fieldset class="list-field" :data-test="testId">
    <legend class="list-field__legend">
      {{ label }}
      <span class="list-field__count text-medium-emphasis" :data-test="`${testId}-count`">
        {{ t('app.strategy.persona.dialog.listCount', { count: modelValue.length, max }) }}
      </span>
    </legend>
    <div v-for="(item, i) in modelValue" :key="i" class="d-flex align-start ga-1 mb-2">
      <v-text-field
        :model-value="item"
        :aria-label="`${label} ${i + 1}`"
        variant="outlined"
        density="compact"
        hide-details="auto"
        :error-messages="lineError(item)"
        data-test="list-item"
        @update:model-value="(v: string) => setAt(i, v)"
      />
      <v-btn
        icon="mdi-close"
        variant="text"
        size="small"
        :aria-label="t('app.strategy.persona.dialog.removeItem')"
        data-test="list-item-remove"
        @click="removeAt(i)"
      />
    </div>
    <v-btn
      variant="text"
      size="small"
      prepend-icon="mdi-plus"
      :disabled="modelValue.length >= max"
      data-test="list-item-add"
      @click="add"
    >
      {{ t('app.strategy.persona.dialog.addItem') }}
    </v-btn>
  </fieldset>
</template>

<style scoped>
.list-field {
  border: 0;
  margin: 0;
  padding: 0;
  min-width: 0;
}
.list-field__legend {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 6px;
}
.list-field__count {
  font-weight: 400;
  margin-left: 6px;
  font-size: 12px;
}
</style>
