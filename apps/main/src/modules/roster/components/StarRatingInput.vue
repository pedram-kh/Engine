<script setup lang="ts">
/**
 * StarRatingInput — a light, interactive 1–5 star control (Sprint 6 Chunk 2a).
 *
 * Net-new this chunk. The roster LIST shipped read-only stars as bare
 * `v-icon`s; `v-rating` was deliberately avoided because it leaks heavily
 * under jsdom (Chunk-5 note). This is a hand-rolled control built from real
 * `<button>`s + `mdi-star` icons so it stays jsdom-unit-testable (no Vuetify
 * overlay/ripple machinery) while remaining a genuine interactive input:
 *
 *   - Click star N → set the rating to N.
 *   - Click the CURRENTLY-selected star → clear the rating (null). This is the
 *     light "toggle off" idiom — no separate clear button needed.
 *   - `readonly` renders the same stars as plain (non-button) icons.
 *
 * Co-located in the roster module (single consumer for now); promote to
 * `@catalyst/ui` only when a second SPA needs it.
 */

import { computed } from 'vue'

interface Props {
  /** Current rating 1–5, or null when unset. */
  modelValue: number | null
  /** Read-only display (no interaction, no buttons). */
  readonly?: boolean
  /** Accessible label for the radiogroup / the read-only group. */
  ariaLabel?: string
  /** Per-star accessible label builder, e.g. (n) => `Rate ${n} of 5`. */
  starLabel?: (value: number) => string
  /** Root data-test anchor. */
  dataTest?: string
}

const props = withDefaults(defineProps<Props>(), {
  readonly: false,
  ariaLabel: undefined,
  starLabel: undefined,
  dataTest: 'star-rating-input',
})

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const STARS = [1, 2, 3, 4, 5] as const

const current = computed(() => props.modelValue ?? 0)

function isFilled(star: number): boolean {
  return star <= current.value
}

function labelFor(star: number): string {
  return props.starLabel?.(star) ?? `${star}`
}

function select(star: number): void {
  if (props.readonly) return
  // Toggle off when re-clicking the already-selected star.
  emit('update:modelValue', star === props.modelValue ? null : star)
}
</script>

<template>
  <div
    class="star-rating"
    :class="{ 'star-rating--readonly': readonly }"
    :role="readonly ? 'img' : 'radiogroup'"
    :aria-label="ariaLabel"
    :data-test="dataTest"
  >
    <template v-for="star in STARS" :key="star">
      <!-- Interactive: a real button per star (jsdom-friendly). -->
      <button
        v-if="!readonly"
        type="button"
        class="star-rating__star"
        role="radio"
        :aria-checked="star === modelValue"
        :aria-label="labelFor(star)"
        :data-test="`${dataTest}-star-${star}`"
        @click="select(star)"
      >
        <v-icon
          :icon="isFilled(star) ? 'mdi-star' : 'mdi-star-outline'"
          color="amber-darken-2"
          size="small"
        />
      </button>
      <!-- Read-only: plain icons, no interaction. -->
      <v-icon
        v-else
        :icon="isFilled(star) ? 'mdi-star' : 'mdi-star-outline'"
        color="amber-darken-2"
        size="small"
        :data-test="`${dataTest}-star-${star}`"
      />
    </template>
  </div>
</template>

<style scoped>
.star-rating {
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.star-rating__star {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 2px;
  background: transparent;
  border: 0;
  border-radius: var(--radius-sm, 4px);
  cursor: pointer;
}

.star-rating__star:hover {
  background: rgb(var(--v-theme-surface-variant));
}

.star-rating__star:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
</style>
