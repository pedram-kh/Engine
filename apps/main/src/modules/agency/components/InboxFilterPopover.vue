<script setup lang="ts">
/**
 * AH-119 — one inbox filter: a chip that opens a searchable multi-select (Figma 16:7838 and
 * 16:8144). Used for brands and for campaigns. Presentation only: the parent owns the selection
 * and the items (with their unread-thread counts) and receives `update:modelValue`.
 *
 * The chip reads the "all" word when nothing is selected, the item's name when one is, and
 * "N selected" otherwise. Rows carry a checkbox, an optional colour dot, the name and the count
 * of threads with unread messages (a count of 0 shows nothing). The footer shows "N selected" and
 * a Clear action while something is selected. The search box filters the loaded items by name.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandColorVar } from '../composables/brandColor'

export interface InboxFilterItem {
  id: string
  name: string
  /** A brand's palette key; omitted for campaigns. */
  color?: string | null
  unreadThreads: number
}

const props = defineProps<{
  modelValue: string[]
  items: InboxFilterItem[]
  /** The chip word while nothing is selected ("All brands"). */
  allLabel: string
  heading: string
  searchPlaceholder: string
  noMatch: string
  icon: string
  /** Shows a colour dot in each row (brands). */
  dots?: boolean
  testKey: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string[]]
}>()

const { t } = useI18n()

const open = ref(false)
const query = ref('')

const selected = computed(() => new Set(props.modelValue))

const visible = computed(() => {
  const needle = query.value.trim().toLowerCase()
  return needle === ''
    ? props.items
    : props.items.filter((item) => item.name.toLowerCase().includes(needle))
})

const chipLabel = computed(() => {
  if (props.modelValue.length === 0) {
    return props.allLabel
  }
  if (props.modelValue.length === 1) {
    return props.items.find((i) => i.id === props.modelValue[0])?.name ?? props.allLabel
  }
  return t(
    'app.messaging.inbox.selectedCount',
    { count: props.modelValue.length },
    props.modelValue.length,
  )
})

function toggle(id: string): void {
  const next = new Set(props.modelValue)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  emit('update:modelValue', [...next])
}

function clear(): void {
  emit('update:modelValue', [])
}
</script>

<template>
  <v-menu v-model="open" :close-on-content-click="false" location="bottom start" offset="6">
    <template #activator="{ props: activatorProps }">
      <button
        v-bind="activatorProps"
        type="button"
        class="inbox-chip"
        :class="{ 'inbox-chip--on': modelValue.length > 0 }"
        :aria-expanded="open"
        :data-test="`inbox-filter-${testKey}`"
      >
        <v-icon :icon="icon" size="14" aria-hidden="true" />
        <span class="inbox-chip__label">{{ chipLabel }}</span>
        <v-icon icon="mdi-chevron-down" size="14" aria-hidden="true" />
      </button>
    </template>

    <div class="inbox-pop" :data-test="`inbox-filter-${testKey}-popover`">
      <label class="inbox-pop__search">
        <v-icon icon="mdi-magnify" size="16" aria-hidden="true" />
        <input
          v-model="query"
          type="text"
          class="inbox-pop__input"
          :placeholder="searchPlaceholder"
          :aria-label="searchPlaceholder"
          :data-test="`inbox-filter-${testKey}-search`"
        />
      </label>

      <div class="inbox-pop__body">
        <p class="inbox-pop__heading">{{ heading }}</p>
        <p v-if="visible.length === 0" class="inbox-pop__empty">{{ noMatch }}</p>
        <button
          v-for="item in visible"
          :key="item.id"
          type="button"
          role="checkbox"
          class="inbox-pop__row"
          :class="{ 'inbox-pop__row--on': selected.has(item.id) }"
          :aria-checked="selected.has(item.id)"
          :data-test="`inbox-filter-${testKey}-option-${item.id}`"
          @click="toggle(item.id)"
        >
          <span class="inbox-pop__box" aria-hidden="true">
            <v-icon v-if="selected.has(item.id)" icon="mdi-check" size="12" />
          </span>
          <span
            v-if="dots"
            class="inbox-pop__dot"
            :style="{ backgroundColor: brandColorVar(item.color) }"
            aria-hidden="true"
          />
          <span class="inbox-pop__name">{{ item.name }}</span>
          <span
            v-if="item.unreadThreads > 0"
            class="inbox-pop__count"
            :data-test="`inbox-filter-${testKey}-count-${item.id}`"
            >{{ item.unreadThreads }}</span
          >
        </button>
      </div>

      <div v-if="modelValue.length > 0" class="inbox-pop__foot">
        <span class="inbox-pop__selected">{{
          t('app.messaging.inbox.selectedCount', { count: modelValue.length }, modelValue.length)
        }}</span>
        <button
          type="button"
          class="inbox-pop__clear"
          :data-test="`inbox-filter-${testKey}-clear`"
          @click="clear"
        >
          {{ t('app.messaging.inbox.clearSelection') }}
        </button>
      </div>
    </div>
  </v-menu>
</template>

<style scoped>
/* The chip (Figma 16:8252): 13px, 8px radius, bordered; accent-tinted once something is chosen. */
.inbox-chip {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2, 8px);
  max-width: 100%;
  padding: 7px 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: var(--radius-lg, 8px);
  background: transparent;
  color: rgb(var(--v-theme-on-surface-variant));
  font: inherit;
  font-size: var(--catalyst-typography-body-sm-size);
  line-height: var(--catalyst-typography-body-sm-line-height);
  cursor: pointer;
}

.inbox-chip--on {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgb(var(--v-theme-primary-container));
  color: rgb(var(--v-theme-primary-text));
}

.inbox-chip:focus-visible,
.inbox-pop__row:focus-visible,
.inbox-pop__clear:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}

.inbox-chip__label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* The popover (Figma 16:8144): 260 wide, raised surface, searchable list, footer. */
.inbox-pop {
  width: 260px;
  max-width: calc(100vw - 32px);
  overflow: hidden;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: var(--radius-xl, 10px);
  background-color: rgb(var(--v-theme-surface));
  box-shadow: 0 8px 24px rgb(var(--v-theme-on-surface) / 0.18);
}

.inbox-pop__search {
  display: flex;
  align-items: center;
  gap: var(--space-2, 8px);
  padding: 10px 12px;
  border-bottom: 1px solid rgb(var(--v-theme-border-color));
  color: rgb(var(--v-theme-text-tertiary));
}

.inbox-pop__input {
  flex: 1 1 auto;
  min-width: 0;
  border: 0;
  outline: 0;
  background: transparent;
  color: rgb(var(--v-theme-on-surface));
  font: inherit;
  font-size: var(--catalyst-typography-body-sm-size);
}

.inbox-pop__body {
  display: flex;
  flex-direction: column;
  max-height: 280px;
  overflow-y: auto;
  padding: 6px;
}

.inbox-pop__heading {
  margin: 0;
  padding: 6px 8px;
  color: rgb(var(--v-theme-text-tertiary));
  font-size: var(--catalyst-typography-overline-size);
  line-height: var(--catalyst-typography-overline-line-height);
  font-weight: 600;
  letter-spacing: 0.6px;
  text-transform: uppercase;
}

.inbox-pop__empty {
  margin: 0;
  padding: 12px 8px;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: var(--catalyst-typography-body-sm-size);
}

.inbox-pop__row {
  display: flex;
  align-items: center;
  gap: var(--space-3, 12px);
  width: 100%;
  padding: 8px;
  border: 0;
  border-radius: var(--radius-md, 6px);
  background: transparent;
  color: rgb(var(--v-theme-on-surface-variant));
  font: inherit;
  font-size: var(--catalyst-typography-body-size);
  line-height: var(--catalyst-typography-body-line-height);
  text-align: left;
  cursor: pointer;
}

.inbox-pop__row:hover {
  background-color: rgb(var(--v-theme-surface-bright));
}

.inbox-pop__row--on,
.inbox-pop__row--on:hover {
  background-color: rgb(var(--v-theme-primary-container));
  color: rgb(var(--v-theme-on-surface));
  font-weight: 500;
}

.inbox-pop__box {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: var(--radius-sm, 4px);
}

.inbox-pop__row--on .inbox-pop__box {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}

.inbox-pop__dot {
  flex: none;
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.inbox-pop__name {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.inbox-pop__count {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
}

.inbox-pop__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px;
  border-top: 1px solid rgb(var(--v-theme-border-color));
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
}

.inbox-pop__selected {
  color: rgb(var(--v-theme-text-tertiary));
}

.inbox-pop__clear {
  padding: 0;
  border: 0;
  background: transparent;
  color: rgb(var(--v-theme-primary-text));
  font: inherit;
  font-weight: 500;
  cursor: pointer;
}
</style>
