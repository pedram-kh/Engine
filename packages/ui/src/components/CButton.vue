<script setup lang="ts">
/**
 * CButton — Catalyst Engine's primary button primitive.
 *
 * Sprint 0 placeholder: thin wrapper over Vuetify's <v-btn>. Subsequent sprints
 * tighten the API (variants, density, loading state, icon-only) per
 * docs/01-UI-UX.md §5 Buttons.
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
    style="border-radius: 6px; text-transform: none"
    @click="(event: MouseEvent) => $emit('click', event)"
  >
    <slot />
  </v-btn>
</template>
