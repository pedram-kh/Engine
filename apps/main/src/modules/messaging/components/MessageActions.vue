<script setup lang="ts">
/**
 * AH-119 Stage B — the "⋯" menu on a message the viewer wrote: Edit (only while the client clock
 * says the 15-minute window is open and the thread is open) and Delete (always, Q12: a closed
 * thread still lets the sender withdraw a message). Delete asks first; both sides will then see
 * "Message deleted".
 *
 * The caller mounts it on OWN messages only and only with the Stage B switch on. The window check
 * is a hint for what to offer; the server stays the authority and a refused call comes back
 * through `confirmDelete` / the edit field with a mapped message.
 *
 * Shared by `ChatPanel`, `RelationshipThreadView` and the agency fork, so it carries no layout of
 * its own beyond the button.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { isEditWindowOpen, messageErrorKey } from '../utils/messageRevision'

const props = defineProps<{
  /** ISO time the message was sent: the edit window runs from it. */
  createdAt: string
  /** Whether the conversation accepts new messages (an edit needs it; a delete does not). */
  threadOpen: boolean
  /** Runs the delete; a rejection keeps the dialog open with the mapped error. */
  confirmDelete: () => Promise<unknown>
}>()

const emit = defineEmits<{ edit: [] }>()

const { t } = useI18n()

const menuOpen = ref(false)
const canEdit = ref(false)
const dialogOpen = ref(false)
const deleting = ref(false)
const error = ref<string | null>(null)

/** Re-read the clock each time the menu opens, so a menu opened at 14:59 does not offer Edit at 15:30. */
function onMenuToggle(open: boolean): void {
  menuOpen.value = open
  if (open) {
    canEdit.value = props.threadOpen && isEditWindowOpen(props.createdAt)
  }
}

const showEditHint = computed(() => props.threadOpen && !canEdit.value)

function startEdit(): void {
  menuOpen.value = false
  emit('edit')
}

function askDelete(): void {
  menuOpen.value = false
  error.value = null
  dialogOpen.value = true
}

async function runDelete(): Promise<void> {
  if (deleting.value) {
    return
  }
  deleting.value = true
  error.value = null
  try {
    await props.confirmDelete()
    dialogOpen.value = false
  } catch (err) {
    error.value = t(messageErrorKey(err, 'app.messaging.errors.deleteFailed'))
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <v-menu :model-value="menuOpen" location="bottom end" @update:model-value="onMenuToggle">
    <template #activator="{ props: activatorProps }">
      <v-btn
        v-bind="activatorProps"
        icon="mdi-dots-horizontal"
        variant="text"
        size="x-small"
        density="comfortable"
        class="message-actions__btn"
        :aria-label="t('app.messaging.actions.menu')"
        data-test="message-actions"
      />
    </template>
    <v-list density="compact" data-test="message-actions-menu">
      <v-list-item
        v-if="canEdit"
        :title="t('app.messaging.actions.edit')"
        prepend-icon="mdi-pencil-outline"
        data-test="message-action-edit"
        @click="startEdit"
      />
      <v-list-item
        v-if="showEditHint"
        :title="t('app.messaging.actions.editHint')"
        disabled
        class="message-actions__hint"
        data-test="message-action-edit-hint"
      />
      <v-list-item
        :title="t('app.messaging.actions.delete')"
        prepend-icon="mdi-delete-outline"
        data-test="message-action-delete"
        @click="askDelete"
      />
    </v-list>
  </v-menu>

  <v-dialog v-model="dialogOpen" max-width="420" data-test="message-delete-dialog">
    <v-card>
      <v-card-title>{{ t('app.messaging.actions.deleteTitle') }}</v-card-title>
      <v-card-text>
        <p class="text-body-2">{{ t('app.messaging.actions.deleteBody') }}</p>
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-3"
          data-test="message-delete-error"
        >
          {{ error }}
        </v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="message-delete-cancel" @click="dialogOpen = false">
          {{ t('app.messaging.actions.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          variant="flat"
          :loading="deleting"
          data-test="message-delete-confirm"
          @click="runDelete"
        >
          {{ t('app.messaging.actions.deleteConfirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.message-actions__btn {
  opacity: 0.7;
}

.message-actions__btn:hover,
.message-actions__btn:focus-visible {
  opacity: 1;
}

.message-actions__hint {
  max-width: 260px;
  white-space: normal;
}
</style>
