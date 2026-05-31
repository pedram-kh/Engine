<script setup lang="ts">
/**
 * CButton — Catalyst Engine's primary button primitive.
 *
 * Sprint 0 placeholder: thin wrapper over Vuetify's <v-btn>. Subsequent sprints
 * tighten the API (variants, density, loading state, icon-only) per
 * docs/01-UI-UX.md §5 Buttons.
 *
 * Styling source-of-truth (Sprint 3.5 Chunk 2, Decision D-fork-a):
 *   CButton encodes VARIANT SEMANTICS only (primary / secondary / ghost /
 *   danger → Vuetify variant + color). It does NOT re-apply primitive
 *   styling. The border-radius (var(--radius-md)) + text-transform:none
 *   live once in the Vuetify `defaults.VBtn` block (both SPAs' plugins),
 *   which this <v-btn> inherits automatically. The previous inline
 *   `style="border-radius:6px;text-transform:none"` here was a second
 *   source for the same decision and was removed to close the drift.
 */

import { computed } from 'vue'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'
type Size = 'small' | 'default' | 'large'

interface Props {
  variant?: Variant
  size?: Size
  loading?: boolean
  disabled?: boolean
  type?: 'button' | 'submit' | 'reset'
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  size: 'default',
  loading: false,
  disabled: false,
  type: 'button',
})

defineEmits<{ click: [event: MouseEvent] }>()

const vuetifyVariant = computed(() => {
  switch (props.variant) {
    case 'primary':
      return 'flat'
    case 'secondary':
      return 'tonal'
    case 'ghost':
      return 'text'
    case 'danger':
      return 'flat'
  }
})

const vuetifyColor = computed(() => {
  switch (props.variant) {
    case 'primary':
      return 'primary'
    case 'secondary':
      return 'secondary'
    case 'ghost':
      return undefined
    case 'danger':
      return 'error'
  }
})
</script>

<template>
  <v-btn
    :variant="vuetifyVariant"
    :color="vuetifyColor"
    :size="size"
    :loading="loading"
    :disabled="disabled"
    :type="type"
    @click="(event: MouseEvent) => $emit('click', event)"
  >
    <slot />
  </v-btn>
</template>
