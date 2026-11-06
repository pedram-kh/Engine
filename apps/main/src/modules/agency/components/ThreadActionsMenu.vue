<script setup lang="ts">
/**
 * AH-119 — the "⋯" menu in the agency thread header: who owns this conversation and, for an
 * admin or a manager (Q6), "Reassign owner". The owner only decides who sees the conversation
 * under the inbox "Mine" chip; it routes nothing and notifies no one.
 *
 * Reassigning opens a dialog listing the agency's ACTIVE members and calls
 * `PATCH /agencies/{agency}/creators/{creator}/owner`; the parent learns the new owner through
 * `changed`. A failed call keeps the dialog open with the error.
 */

import { ApiError } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { membersApi } from '@/modules/agency-users/api/members.api'
import { messageInboxApi } from '@/modules/messaging/api/messageInbox.api'
import { MESSAGE_ERROR_KEYS } from '@/modules/messaging/utils/messageRevision'

const props = defineProps<{
  agencyId: string
  creatorUlid: string
  owner: { id: string; name: string } | null
  canReassign: boolean
}>()

const emit = defineEmits<{
  changed: [owner: { id: string; name: string }]
}>()

const { t } = useI18n()

const menuOpen = ref(false)
const dialogOpen = ref(false)
const members = ref<Array<{ id: string; name: string }>>([])
const loadingMembers = ref(false)
const selected = ref<string | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)
const done = ref<string | null>(null)

const ownerLine = computed(() =>
  props.owner === null
    ? t('app.messaging.inbox.noOwner')
    : t('app.messaging.thread.ownerLabel', { name: props.owner.name }),
)

async function openDialog(): Promise<void> {
  menuOpen.value = false
  error.value = null
  selected.value = props.owner?.id ?? null
  dialogOpen.value = true
  loadingMembers.value = true
  try {
    const res = await membersApi.list(props.agencyId, { per_page: 100, sort: 'name', order: 'asc' })
    members.value = res.data
      .filter((m) => m.attributes.status === 'active')
      .map((m) => ({ id: m.attributes.user_id, name: m.attributes.name }))
  } catch {
    error.value = t('app.messaging.thread.reassignError')
  } finally {
    loadingMembers.value = false
  }
}

async function confirm(): Promise<void> {
  const target = members.value.find((m) => m.id === selected.value)
  if (target === undefined || saving.value) {
    return
  }
  saving.value = true
  error.value = null
  try {
    await messageInboxApi.reassignOwner(props.agencyId, props.creatorUlid, target.id)
    emit('changed', target)
    dialogOpen.value = false
    done.value = t('app.messaging.thread.reassignDone', { name: target.name })
  } catch (err) {
    // AH-119 Stage B: `owner.not_a_member` has its own localised line; other API errors keep the
    // server's message as before.
    const mapped = err instanceof ApiError ? MESSAGE_ERROR_KEYS[err.code] : undefined
    error.value =
      mapped !== undefined
        ? t(mapped)
        : err instanceof ApiError
          ? err.message
          : t('app.messaging.thread.reassignError')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-menu v-model="menuOpen" location="bottom end">
    <template #activator="{ props: activatorProps }">
      <v-btn
        v-bind="activatorProps"
        icon="mdi-dots-horizontal"
        variant="outlined"
        size="small"
        :aria-label="t('app.messaging.thread.moreActions')"
        data-test="thread-more"
      />
    </template>
    <v-list density="compact" data-test="thread-more-menu">
      <v-list-item :title="ownerLine" disabled data-test="thread-owner-line" />
      <v-list-item
        v-if="canReassign"
        :title="t('app.messaging.thread.reassignOwner')"
        prepend-icon="mdi-account-switch-outline"
        data-test="thread-reassign"
        @click="openDialog"
      />
    </v-list>
  </v-menu>

  <v-dialog v-model="dialogOpen" max-width="420" data-test="thread-reassign-dialog">
    <v-card>
      <v-card-title>{{ t('app.messaging.thread.reassignTitle') }}</v-card-title>
      <v-card-text>
        <p class="mb-3 text-body-2">{{ t('app.messaging.thread.reassignHint') }}</p>
        <v-select
          v-model="selected"
          :items="members"
          item-title="name"
          item-value="id"
          :label="t('app.messaging.thread.reassignSelect')"
          :loading="loadingMembers"
          variant="outlined"
          density="comfortable"
          hide-details="auto"
          data-test="thread-reassign-select"
        />
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-3"
          data-test="thread-reassign-error"
        >
          {{ error }}
        </v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="thread-reassign-cancel" @click="dialogOpen = false">
          {{ t('app.messaging.thread.reassignCancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          :disabled="selected === null || selected === owner?.id"
          data-test="thread-reassign-confirm"
          @click="confirm"
        >
          {{ t('app.messaging.thread.reassignConfirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar
    :model-value="done !== null"
    timeout="4000"
    data-test="thread-reassign-done"
    @update:model-value="done = null"
  >
    {{ done }}
  </v-snackbar>
</template>
