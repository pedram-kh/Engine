<script setup lang="ts">
/**
 * "Deletion requested": the chip an admin sees beside an account that has asked to leave
 * (AH-104c C2-4). Shows only when the reader says so, so it never shows for an erased account
 * (which has its own label) or for one whose request was cancelled.
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorDeletionPending, type AccountLike, type CreatorLike } from './creatorLabel'

defineOptions({ name: 'DeletionRequestedChip' })

const props = defineProps<{ account: CreatorLike | AccountLike | null | undefined }>()

const { t } = useI18n()
const visible = computed(() => creatorDeletionPending(props.account))
</script>

<template>
  <v-chip
    v-if="visible"
    size="x-small"
    color="warning"
    variant="tonal"
    class="ml-2"
    data-testid="admin-deletion-requested-chip"
  >
    {{ t('admin.creators.deletion_requested') }}
  </v-chip>
</template>
