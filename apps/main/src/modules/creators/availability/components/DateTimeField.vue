<script setup lang="ts">
/**
 * DateTimeField — module-local date (+ optional time) control for the
 * availability dialog (Sprint 5 Chunk B, D-b10).
 *
 * Net-new because the SPA had no date/time picker. Per D-b10 it is built
 * LOCAL to the availability module (not promoted to `@catalyst/ui`) until a
 * second consumer appears — premature shared-API design avoided.
 *
 * Composition (D-b10): Vuetify `VDatePicker` (in a menu, behind a readonly
 * text field) + a native `type="time"` text field. The time picker is NOT
 * hand-rolled.
 *
 * Values are plain strings — date `'YYYY-MM-DD'`, time `'HH:mm'` — so the
 * parent owns all timezone conversion (this control is tz-agnostic).
 */

import { computed, ref } from 'vue'

const props = withDefaults(
  defineProps<{
    date: string
    time?: string
    showTime?: boolean
    dateLabel: string
    timeLabel?: string
    dateErrors?: readonly string[]
    timeErrors?: readonly string[]
    dataTestPrefix?: string
  }>(),
  {
    time: '',
    showTime: true,
    timeLabel: '',
    dateErrors: () => [],
    timeErrors: () => [],
    dataTestPrefix: 'dtf',
  },
)

const emit = defineEmits<{
  'update:date': [value: string]
  'update:time': [value: string]
}>()

const menu = ref(false)

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

/** `'YYYY-MM-DD'` → a local `Date` at midnight (VDatePicker's model shape). */
const pickerModel = computed<Date | null>(() => {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.date)
  if (match === null) {
    return null
  }
  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
})

/** VDatePicker emits a local `Date`; read its local Y/M/D back to a key. */
function onPick(value: unknown): void {
  const picked = Array.isArray(value) ? value[0] : value
  if (picked instanceof Date && !Number.isNaN(picked.getTime())) {
    emit(
      'update:date',
      `${picked.getFullYear()}-${pad2(picked.getMonth() + 1)}-${pad2(picked.getDate())}`,
    )
  }
  menu.value = false
}
</script>

<template>
  <div class="dtf">
    <v-menu v-model="menu" :close-on-content-click="false" location="bottom start">
      <template #activator="{ props: menuProps }">
        <v-text-field
          v-bind="menuProps"
          :model-value="date"
          :label="dateLabel"
          :error-messages="dateErrors as string[]"
          readonly
          append-inner-icon="mdi-calendar"
          density="comfortable"
          :data-test="`${dataTestPrefix}-date`"
        />
      </template>
      <v-date-picker
        :model-value="pickerModel"
        hide-header
        show-adjacent-months
        :data-test="`${dataTestPrefix}-picker`"
        @update:model-value="onPick"
      />
    </v-menu>

    <v-text-field
      v-if="showTime"
      :model-value="time"
      :label="timeLabel"
      :error-messages="timeErrors as string[]"
      type="time"
      density="comfortable"
      :data-test="`${dataTestPrefix}-time`"
      @update:model-value="emit('update:time', $event)"
    />
  </div>
</template>

<style scoped>
.dtf {
  display: flex;
  gap: var(--space-3, 12px);
  align-items: flex-start;
}

.dtf > * {
  flex: 1;
}
</style>
