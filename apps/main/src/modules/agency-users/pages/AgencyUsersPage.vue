<script setup lang="ts">
/**
 * Agency users page — lists existing members + pending invitations.
 * "Invite user" button opens the InviteUserModal (Q1: modal).
 * Visible only to agency_admin (route guard + UI gating).
 */

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import InviteUserModal from '../components/InviteUserModal.vue'

const { t } = useI18n()
const agencyStore = useAgencyStore()

const inviteModalOpen = ref(false)
const successMessage = ref<string | null>(null)

// Use agency store memberships as the "existing members" list.
// This is derived from the bootstrapped user data — no extra API call.
const existingMembers = agencyStore.memberships

const memberHeaders = [
  { title: t('app.agencyUsers.columns.name'), key: 'agency_name', sortable: false },
  { title: t('app.agencyUsers.columns.role'), key: 'role', sortable: false },
]

function onInvited(email: string): void {
  successMessage.value = t('app.agencyUsers.invite.success', { email })
  setTimeout(() => {
    successMessage.value = null
  }, 5000)
}
</script>

<template>
  <div data-test="agency-users-page">
    <div class="d-flex align-center justify-space-between mb-6">
      <h1 class="text-h5 ma-0" data-test="agency-users-heading">
        {{ t('app.agencyUsers.title') }}
      </h1>
      <v-btn
        v-if="agencyStore.isAdmin"
        color="primary"
        prepend-icon="mdi-account-plus"
        data-test="invite-user-btn"
        @click="inviteModalOpen = true"
      >
        {{ t('app.agencyUsers.invite.button') }}
      </v-btn>
    </div>

    <!-- Success toast -->
    <v-alert
      v-if="successMessage"
      type="success"
      variant="tonal"
      closable
      class="mb-4"
      data-test="invite-success-alert"
      @click:close="successMessage = null"
    >
      {{ successMessage }}
    </v-alert>

    <!-- Current members -->
    <h2 class="text-subtitle-1 font-weight-semibold mb-3">Current members</h2>
    <v-data-table
      :headers="memberHeaders"
      :items="existingMembers"
      item-value="agency_id"
      hide-default-footer
      class="mb-6"
      data-test="members-table"
    >
      <template #item.role="{ item }">
        {{ t(`app.agencyUsers.roles.${item.role}`) }}
      </template>
    </v-data-table>

    <!-- Invite user modal -->
    <InviteUserModal v-model="inviteModalOpen" @invited="onInvited" />
  </div>
</template>
