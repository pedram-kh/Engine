<script setup lang="ts">
/**
 * AH-114 (S3.3) — the agency filter pill (Figma "filter chip", Discover 17:441): a rounded
 * pill with an uppercase label that opens a short list of choices. Unset it is a surface pill
 * with the default border ("Language"); set it takes the container fill, the accent border and
 * the accent text and reads "Country: United States", with a small close button that clears it.
 *
 * It is a controlled input: `modelValue` is the chosen value or null, and the component only
 * emits `update:modelValue`. Choosing the value that is already set clears it, so the list
 * needs no "All" entry (and no string of its own). The close button's accessible name is
 * Vuetify's own "Clear {label}".
 *
 * Colour and type come from the agency theme through `--v-theme-*` and the type tokens; the
 * overlay is a Vuetify menu, which carries the theme class itself.
 */

import { computed, ref } from 'vue'
import { useLocale } from 'vuetify'

export interface FilterPillItem {
  title: string
  value: string
}

const props = defineProps<{
  /** The pre-localised name of the filter ("Country"). */
  label: string
  items: FilterPillItem[]
  modelValue: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()

const { t } = useLocale()
const open = ref(false)

const selected = computed(() => props.items.find((i) => i.value === props.modelValue) ?? null)
const applied = computed(() => selected.value !== null)
const text = computed(() =>
  selected.value === null ? props.label : `${props.label}: ${selected.value.title}`,
)
const clearLabel = computed(() => t('$vuetify.input.clear', props.label))

function pick(value: string): void {
  emit('update:modelValue', value === props.modelValue ? null : value)
  open.value = false
}

function clear(): void {
  emit('update:modelValue', null)
}
</script>

<template>
  <span class="agency-filter-pill" :class="{ 'agency-filter-pill--applied': applied }">
    <v-menu v-model="open" location="bottom start">
      <template #activator="{ props: activator }">
        <button
          v-bind="activator"
          type="button"
          class="agency-filter-pill__button"
          aria-haspopup="listbox"
          :aria-expanded="open"
          data-test="agency-filter-pill-button"
        >
          {{ text }}
        </button>
      </template>
      <v-list class="agency-filter-pill__list" density="compact" role="listbox">
        <v-list-item
          v-for="item in items"
          :key="item.value"
          :title="item.title"
          :active="item.value === modelValue"
          role="option"
          :aria-selected="item.value === modelValue"
          data-test="agency-filter-pill-option"
          @click="pick(item.value)"
        />
      </v-list>
    </v-menu>
    <button
      v-if="applied"
      type="button"
      class="agency-filter-pill__clear"
      :aria-label="clearLabel"
      data-test="agency-filter-pill-clear"
      @click="clear"
    >
      <v-icon icon="mdi-close" size="12" />
    </button>
  </span>
</template>

<style scoped>
.agency-filter-pill {
  display: inline-flex;
  flex: none;
  align-items: center;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 999px;
  background-color: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-filter-pill--applied {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgb(var(--v-theme-primary-container));
  color: rgb(var(--v-theme-primary-text));
}

.agency-filter-pill__button {
  padding: 8px 12px;
  border-radius: 999px;
  color: inherit;
  font-family: inherit;
  font-size: 10.5px;
  font-weight: 700;
  line-height: 14px;
  letter-spacing: 0.4px;
  text-transform: uppercase;
  white-space: nowrap;
  cursor: pointer;
}

.agency-filter-pill--applied .agency-filter-pill__button {
  padding-inline-end: 4px;
}

.agency-filter-pill__clear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  margin-inline-end: 8px;
  border-radius: 999px;
  color: inherit;
  cursor: pointer;
}

.agency-filter-pill__button:focus-visible,
.agency-filter-pill__clear:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.agency-filter-pill__list {
  max-height: 320px;
  overflow-y: auto;
}
</style>
