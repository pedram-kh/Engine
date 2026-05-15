<script setup lang="ts">
/**
 * EditFieldRow — admin per-field edit affordance (Sprint 3 Chunk 4 sub-step 9).
 *
 * Renders a labelled value with a trailing "edit" icon-button. The
 * page slots in its own display rendering via the default slot (so
 * the shared display components like `CategoryChips`, `CountryDisplay`,
 * `LanguageList` are still the single source of truth for how a
 * value renders, per Decision C1 = display-shared). When the admin
 * clicks the pencil button the component emits `edit` and the page
 * is responsible for opening the {@link EditFieldModal} with the
 * appropriate config.
 *
 * a11y:
 *   - The edit button has an accessible name composed of an i18n
 *     prefix + the field label, so screen-readers announce "Edit
 *     display name" rather than just "Edit".
 *   - The button uses `<v-btn icon variant="text">` which renders a
 *     real `<button>` element.
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'EditFieldRow' })

const props = defineProps<{
  labelKey: string
  testId: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'edit'): void
}>()

const { t } = useI18n()

const editAriaLabel = computed(() =>
  t('admin.creators.detail.edit.button_aria_label', { field: t(props.labelKey) }),
)

function onEdit(): void {
  if (props.disabled === true) return
  emit('edit')
}
</script>

<template>
  <div class="edit-field-row" :data-testid="testId">
    <div class="edit-field-row__label">
      <span class="edit-field-row__label-text">{{ t(labelKey) }}</span>
    </div>
    <div class="edit-field-row__value">
      <slot />
    </div>
    <v-btn
      icon="mdi-pencil"
      variant="text"
      size="small"
      :disabled="disabled === true"
      :aria-label="editAriaLabel"
      :data-testid="`${testId}-edit`"
      @click="onEdit"
    />
  </div>
</template>

<style scoped>
.edit-field-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
}

.edit-field-row__label {
  flex: 0 0 180px;
  font-weight: 500;
}

.edit-field-row__value {
  flex: 1 1 auto;
  min-width: 0;
}
</style>
