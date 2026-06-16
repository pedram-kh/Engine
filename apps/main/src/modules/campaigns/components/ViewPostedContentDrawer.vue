<script setup lang="ts">
/**
 * Agency read-only "view posted content" drawer (posted-content visibility
 * chunk). Opened from any Creators-tab row that already has posted content
 * (`verification_status !== null`) — regardless of the verification outcome.
 *
 * It reuses the agency assignment-detail endpoint ({@see campaignsApi.showAssignment})
 * and renders the self-reported post(s): the live URL, platform, verification
 * status, and timestamps. The newest row is the current post; older rows are
 * kept as history (an ACT2 fresh resubmit supersedes a failed post).
 *
 * Strictly read-only — the verification-failure ACTIONS live in
 * {@see ResolveVerificationDrawer} (gated to `not_found`/`mismatch`).
 */

import {
  formatDateTime,
  type AgencyAssignmentDetailResource,
  type CampaignAssignmentResource,
  type PostedContentVerificationStatus,
} from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  assignment: CampaignAssignmentResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const { t, locale } = useI18n()

const detail = ref<AgencyAssignmentDetailResource | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)

const postedContent = computed(() => detail.value?.relationships.posted_content ?? [])

function statusColor(status: PostedContentVerificationStatus): string {
  if (status === 'verified') return 'success'
  if (status === 'pending') return 'info'
  return 'error'
}

async function load(): Promise<void> {
  const assignment = props.assignment
  if (assignment === null) return
  loading.value = true
  loadError.value = null
  detail.value = null
  try {
    const res = await campaignsApi.showAssignment(props.agencyId, props.campaignId, assignment.id)
    detail.value = res.data
  } catch {
    loadError.value = t('app.campaigns.viewPost.loadFailed')
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
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="640"
    scrollable
    data-test="view-post-drawer"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="d-flex align-center">
        <span class="text-h6">{{ t('app.campaigns.viewPost.title') }}</span>
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
          data-test="view-post-close"
          @click="close"
        />
      </v-card-title>

      <v-divider />

      <v-card-text>
        <v-skeleton-loader v-if="loading" type="article" data-test="view-post-skeleton" />

        <v-alert
          v-else-if="loadError"
          type="error"
          variant="tonal"
          data-test="view-post-load-error"
        >
          {{ loadError }}
        </v-alert>

        <v-alert
          v-else-if="postedContent.length === 0"
          type="info"
          variant="tonal"
          data-test="view-post-empty"
        >
          {{ t('app.campaigns.viewPost.empty') }}
        </v-alert>

        <template v-else>
          <v-card
            v-for="(post, index) in postedContent"
            :key="post.id"
            variant="outlined"
            class="mb-3"
            :data-test="`view-post-row-${index}`"
          >
            <v-card-text>
              <div class="d-flex align-center ga-2 mb-1">
                <span class="text-subtitle-2">{{ post.attributes.platform }}</span>
                <v-chip
                  size="x-small"
                  :color="statusColor(post.attributes.verification_status)"
                  variant="tonal"
                  :data-test="`view-post-status-${index}`"
                >
                  {{
                    t(`app.campaigns.viewPost.verification.${post.attributes.verification_status}`)
                  }}
                </v-chip>
                <v-chip
                  v-if="index === 0"
                  size="x-small"
                  variant="text"
                  data-test="view-post-current"
                >
                  {{ t('app.campaigns.viewPost.current') }}
                </v-chip>
              </div>
              <a
                :href="post.attributes.post_url"
                target="_blank"
                rel="noopener noreferrer"
                class="text-body-2 text-truncate d-block"
                :data-test="`view-post-url-${index}`"
              >
                {{ post.attributes.post_url }}
              </a>
              <div class="text-caption text-medium-emphasis mt-2">
                {{ t('app.campaigns.viewPost.postedAt') }}:
                {{ formatDateTime(post.attributes.posted_at, locale) }}
                <template v-if="post.attributes.verified_at">
                  · {{ t('app.campaigns.viewPost.verifiedAt') }}:
                  {{ formatDateTime(post.attributes.verified_at, locale) }}
                </template>
              </div>
            </v-card-text>
          </v-card>

          <p class="text-caption text-medium-emphasis">
            {{ t('app.campaigns.viewPost.simulated') }}
          </p>
        </template>
      </v-card-text>

      <v-divider />

      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="view-post-done" @click="close">
          {{ t('app.campaigns.viewPost.close') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
