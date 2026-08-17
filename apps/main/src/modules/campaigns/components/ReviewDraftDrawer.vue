<script setup lang="ts">
/**
 * Agency draft-review drawer (Sprint 9 Chunk 2, D-8). A WIDE dialog (the
 * ReinviteDialog pattern — no v-navigation-drawer exists in this app) opened
 * from a `draft_submitted` row in the Creators tab. It:
 *
 *   - loads the agency-side assignment detail (latest draft + version history +
 *     posted content with signed media URLs);
 *   - hosts the shared `DraftReviewPanel` (eyes-on fix batch, 2026-08-17) for
 *     the actual preview / feedback / Approve-Request changes-Reject actions
 *     — the board card drawer's Drafts tab mounts the SAME panel, so this
 *     drawer is now just the dialog chrome (title, close, the panel, and a
 *     secondary "Close" button that only makes sense while there's still
 *     something to act on).
 *
 * The post-verification state (D-12) is labelled "simulated" — it is the mock
 * SocialPlatformProvider behind the scenes, not a real platform check.
 */

import type {
  AgencyAssignmentDetailResource,
  CampaignAssignmentResource,
} from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import DraftReviewPanel from './DraftReviewPanel.vue'
import { campaignsApi } from '../api/campaigns.api'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  assignment: CampaignAssignmentResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  reviewed: [message: string]
}>()

const { t } = useI18n()

const detail = ref<AgencyAssignmentDetailResource | null>(null)
const loading = ref(false)
const loadError = ref(false)
// Gates the secondary "Close"/cancel button in the footer — it only makes
// sense while there's still something to cancel out of (the panel's own
// `canAct` mirrored here since the drawer chrome needs it independently).
const canAct = computed(() => detail.value?.attributes.status === 'draft_submitted')

async function load(): Promise<void> {
  const assignment = props.assignment
  if (assignment === null) return
  loading.value = true
  loadError.value = false
  detail.value = null
  try {
    const res = await campaignsApi.showAssignment(props.agencyId, props.campaignId, assignment.id)
    detail.value = res.data
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void load()
  },
)

function close(): void {
  emit('update:modelValue', false)
}

// Approving/rejecting/requesting changes closes the drawer (the existing
// behavior) — the panel itself has no dialog to close, so this drawer does
// it on the panel's `reviewed` emit.
function onPanelReviewed(message: string): void {
  emit('reviewed', message)
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="900"
    scrollable
    data-test="review-draft-drawer"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="d-flex align-center">
        <span class="text-h6">{{ t('app.campaigns.review.title') }}</span>
        <span
          v-if="assignment?.attributes.creator?.display_name"
          class="text-body-2 text-medium-emphasis ml-2"
        >
          · {{ assignment.attributes.creator.display_name }}
        </span>
        <v-spacer />
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="review-close"
          @click="close"
        />
      </v-card-title>

      <v-divider />

      <v-card-text>
        <DraftReviewPanel
          :agency-id="agencyId"
          :campaign-id="campaignId"
          :assignment-id="assignment?.id ?? null"
          :detail="detail"
          :loading="loading"
          :load-error="loadError"
          can-review
          @reviewed="onPanelReviewed"
        />
      </v-card-text>

      <v-divider />

      <!-- The secondary "Close"/cancel button — only shown while there's
           still something to cancel out of. Once a draft is reviewed, the
           title bar's "X" is the only close control (nothing left to
           cancel). The three review actions themselves now live inside
           `DraftReviewPanel`, right beside the feedback field they use. -->
      <v-card-actions v-if="canAct">
        <v-btn variant="text" data-test="review-cancel" @click="close">
          {{ t('app.campaigns.review.close') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
