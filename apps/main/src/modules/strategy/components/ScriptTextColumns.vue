<script setup lang="ts">
/**
 * PL-3.2b — one spoken text (a hook's audio, a hook's overlay, a line's script) in the languages the view shows: ONE column with the
 * language code above it ("EN", 10 / 700), or TWO side by side (English, then the chosen local language behind a left border,
 * frame 13:3718). Under 600 px two columns stack, each keeps its language label. A market with English only never gets a second
 * column. Every box is an auto-growing text box; the counter shows near the limit only. Plain text only.
 */
import { computed } from 'vue'

import { EN } from '../scriptsLogic'
import AutoGrowTextarea from './AutoGrowTextarea.vue'

const props = defineProps<{
  /** The columns to draw, in order (`['en']` or `['en', 'sk']`, or one local language). */
  languages: string[]
  /** The draft text of every language. */
  texts: Record<string, string>
  max: number
  placeholder: string
  label: string
  /** `data-test` of the English box; the others add `-{language}`. */
  testId: string
  /** The id of the English box (the "Add overlay" button focuses it). */
  boxId?: string
}>()
const emit = defineEmits<{ edit: [language: string, value: string] }>()

const columns = computed(() =>
  props.languages.map((code) => ({
    code,
    label: code.toUpperCase(),
    text: props.texts[code] ?? '',
  })),
)
function counter(len: number): boolean {
  return len > props.max * 0.8
}
</script>

<template>
  <div class="tcols" :class="{ 'tcols--two': columns.length > 1 }">
    <div
      v-for="(c, i) in columns"
      :key="c.code"
      class="tcols__col"
      :class="{ 'tcols__col--second': i > 0 }"
      :lang="c.code"
      :data-language="c.code"
    >
      <span class="tcols__lang">{{ c.label }}</span>
      <AutoGrowTextarea
        :id="c.code === EN ? boxId : undefined"
        :model-value="c.text"
        class="tcols__input"
        :placeholder="placeholder"
        :aria-label="`${label} (${c.label})`"
        :error="c.text.length > max"
        :data-test="c.code === EN ? testId : `${testId}-${c.code}`"
        @update:model-value="(v: string) => emit('edit', c.code, v)"
      />
      <span
        v-if="counter(c.text.length)"
        class="tcols__count"
        :class="{ 'tcols__count--over': c.text.length > max }"
        >{{ c.text.length }} / {{ max }}</span
      >
    </div>
  </div>
</template>

<style scoped>
.tcols {
  display: flex;
  flex: 1 1 0;
  gap: 10px;
  min-width: 0;
}
.tcols__col {
  display: flex;
  flex: 1 1 0;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.tcols__col--second {
  padding-left: 10px;
  border-left: 1px solid rgb(var(--v-theme-border-color));
}
.tcols__lang {
  font-size: 10px;
  font-weight: 700;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.tcols__input {
  margin: 0;
}
.tcols__count {
  align-self: flex-end;
  font-size: 11px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.tcols__count--over {
  color: rgb(var(--v-theme-error));
  font-weight: 600;
}
@media (max-width: 599px) {
  .tcols--two {
    flex-direction: column;
    gap: 8px;
  }
  .tcols__col--second {
    padding-left: 0;
    border-left: 0;
    padding-top: 8px;
    border-top: 1px solid rgb(var(--v-theme-border-color));
  }
}
</style>
