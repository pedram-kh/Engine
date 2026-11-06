<script setup lang="ts">
/**
 * AH-119 Stage B — the inline edit field that replaces a bubble's text while the sender edits it:
 * a textarea with Save and Cancel. Save is off for an empty or unchanged text. A refused save (the
 * window closed, the thread closed, not editable) keeps the field open with the mapped message and
 * the caller's optimistic edit already rolled back; Cancel closes it.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { messageErrorKey } from '../utils/messageRevision'

/** Mirrors the server bound (1 to 5000 characters). */
const MAX_LENGTH = 5000

const props = defineProps<{
  /** The text being edited. */
  initial: string
  /** Runs the edit; a rejection keeps the field open with the mapped error. */
  save: (body: string) => Promise<unknown>
}>()

const emit = defineEmits<{ done: [] }>()

const { t } = useI18n()

const draft = ref(props.initial)
const saving = ref(false)
const error = ref<string | null>(null)

const trimmed = computed(() => draft.value.trim())
const canSave = computed(
  () =>
    !saving.value &&
    trimmed.value !== '' &&
    trimmed.value !== props.initial.trim() &&
    trimmed.value.length <= MAX_LENGTH,
)

async function submit(): Promise<void> {
  if (!canSave.value) {
    return
  }
  saving.value = true
  error.value = null
  try {
    await props.save(trimmed.value)
    emit('done')
  } catch (err) {
    error.value = t(messageErrorKey(err, 'app.messaging.errors.editFailed'))
  } finally {
    saving.value = false
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('done')
    return
  }
  // Ctrl/Cmd+Enter saves; a plain Enter stays a newline (the field is a multi-line editor).
  if (event.key === 'Enter' && (event.metaKey || event.ctrlKey) && !event.isComposing) {
    event.preventDefault()
    void submit()
  }
}
</script>

<template>
  <div class="message-edit" data-test="message-edit">
    <v-textarea
      v-model="draft"
      :aria-label="t('app.messaging.actions.editFieldLabel')"
      rows="1"
      auto-grow
      max-rows="6"
      density="compact"
      variant="outlined"
      hide-details="auto"
      autofocus
      :maxlength="MAX_LENGTH"
      data-test="message-edit-input"
      @keydown="onKeydown"
    />
    <p v-if="error" class="message-edit__error" role="alert" data-test="message-edit-error">
      {{ error }}
    </p>
    <div class="message-edit__row">
      <v-btn
        variant="text"
        size="small"
        :disabled="saving"
        data-test="message-edit-cancel"
        @click="emit('done')"
      >
        {{ t('app.messaging.actions.cancel') }}
      </v-btn>
      <v-btn
        color="primary"
        variant="flat"
        size="small"
        :loading="saving"
        :disabled="!canSave"
        data-test="message-edit-save"
        @click="submit"
      >
        {{ t('app.messaging.actions.save') }}
      </v-btn>
    </div>
  </div>
</template>

<style scoped>
.message-edit {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: min(260px, 60vw);
}

.message-edit__error {
  margin: 0;
  color: rgb(var(--v-theme-error));
  font-size: 0.8rem;
}

.message-edit__row {
  display: flex;
  justify-content: flex-end;
  gap: 4px;
}
</style>
