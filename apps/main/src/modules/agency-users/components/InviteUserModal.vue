<script setup lang="ts">
/**
 * Invite user modal — Q1 answer: modal from the agency-users list page.
 * 2-field form: email + role.
 * On success: emits 'invited' event so the parent can update the pending list.
 */

import type { AgencyRole } from '@catalyst/api-client'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { invitationsApi } from '../api/invitations.api'

defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [open: boolean]
  invited: [email: string]
}>()

const { t } = useI18n()
const agencyStore = useAgencyStore()

const email = ref('')
const role = ref<AgencyRole>('agency_manager')
const submitting = ref(false)
const error = ref<string | null>(null)

const roleOptions: { title: string; value: AgencyRole }[] = [
  { title: t('app.agencyUsers.roles.agency_admin'), value: 'agency_admin' },
  { title: t('app.agencyUsers.roles.agency_manager'), value: 'agency_manager' },
  { title: t('app.agencyUsers.roles.agency_staff'), value: 'agency_staff' },
]

function close(): void {
  emit('update:modelValue', false)
  email.value = ''
  role.value = 'agency_manager'
  error.value = null
}

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  error.value = null

  try {
    await invitationsApi.create(agencyId, { email: email.value, role: role.value })
    const invitedEmail = email.value
    close()
    emit('invited', invitedEmail)
  } catch {
    error.value = t('app.agencyUsers.invite.errors.failed')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="480"
    data-test="invite-user-modal"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <v-card>
      <v-card-title class="text-h6 pa-4" data-test="invite-modal-title">
        {{ t('app.agencyUsers.invite.title') }}
      </v-card-title>

      <v-card-text>
        <form novalidate data-test="invite-form" @submit.prevent="onSubmit">
          <v-text-field
            v-model="email"
            :label="t('app.agencyUsers.invite.fields.email')"
            type="email"
            autocomplete="email"
            required
            data-test="invite-email"
          />

          <v-select
            v-model="role"
            :label="t('app.agencyUsers.invite.fields.role')"
            :items="roleOptions"
            item-title="title"
            item-value="value"
            required
            data-test="invite-role"
          />

          <div
            v-if="error"
            role="alert"
            aria-live="polite"
            class="text-error text-body-2 mb-2"
            data-test="invite-error"
          >
            {{ error }}
          </div>
        </form>
      </v-card-text>

      <v-card-actions class="px-4 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="submitting" data-test="invite-cancel" @click="close">
          {{ t('app.brands.archive.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="submitting"
          :disabled="submitting || !email"
          data-test="invite-submit"
          @click="onSubmit"
        >
          {{
            submitting ? t('app.agencyUsers.invite.submitting') : t('app.agencyUsers.invite.submit')
          }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
