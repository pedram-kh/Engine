<script setup lang="ts">
/**
 * Agency verification-failure resolution drawer (verification-resolution chunk,
 * D-4/D-5/D-6/D-7). Opened from a `posted` row whose latest posted content
 * FAILED auto-verification (`not_found`/`mismatch`) in the Creators tab. It
 * loads the agency-side assignment detail (the posted content + its failure
 * reason) and offers the three resolution actions:
 *
 *   - Manually verify (ACT1) — the human override → `manually_verified`
 *     (payment-eligible). The override reason is REQUIRED (422 binds on `reason`).
 *   - Request a fresh resubmit (ACT2) — `posted → approved`; the creator
 *     re-posts. Optional feedback to the creator.
 *   - Request an in-place fix (ACT3) — a nudge, NO state change; the creator
 *     edits the URL in place. Optional feedback to the creator.
 *
 * Verification is the mock SocialPlatformProvider behind the scenes — the
 * failure is labelled "simulated" (mirrors the review drawer, D-12).
 */

import {
  ApiError,
  extractFieldErrors,
  type AgencyAssignmentDetailResource,
  type CampaignAssignmentResource,
} from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'

type ResolutionField = 'reason'
type ActionKind = 'verify' | 'fresh' | 'in_place'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  assignment: CampaignAssignmentResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  resolved: [message: string]
}>()

const { t } = useI18n()

const detail = ref<AgencyAssignmentDetailResource | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const note = ref('')
const fieldErrors = ref<Partial<Record<ResolutionField, readonly string[]>>>({})
const actionError = ref<string | null>(null)
const submitting = ref<ActionKind | null>(null)

const postedContent = computed(() => detail.value?.relationships.posted_content ?? [])
// The active (latest) posted-content row carries the failure reason.
const latestPost = computed(() => postedContent.value[postedContent.value.length - 1] ?? null)
const canAct = computed(() => detail.value?.attributes.status === 'posted')

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
    loadError.value = t('app.campaigns.resolution.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      note.value = ''
      fieldErrors.value = {}
      actionError.value = null
      void load()
    }
  },
)

function close(): void {
  emit('update:modelValue', false)
}

async function runAction(kind: ActionKind): Promise<void> {
  const assignment = props.assignment
  if (assignment === null || submitting.value !== null) return

  submitting.value = kind
  fieldErrors.value = {}
  actionError.value = null
  const trimmed = note.value.trim()
  try {
    if (kind === 'verify') {
      await campaignsApi.manuallyVerify(props.agencyId, props.campaignId, assignment.id, {
        reason: trimmed,
      })
      emit('resolved', t('app.campaigns.resolution.toast.verified'))
    } else if (kind === 'fresh') {
      await campaignsApi.requestResubmitFresh(props.agencyId, props.campaignId, assignment.id, {
        feedback: trimmed === '' ? null : trimmed,
      })
      emit('resolved', t('app.campaigns.resolution.toast.resubmitFresh'))
    } else {
      await campaignsApi.requestResubmitInPlace(props.agencyId, props.campaignId, assignment.id, {
        feedback: trimmed === '' ? null : trimmed,
      })
      emit('resolved', t('app.campaigns.resolution.toast.resubmitInPlace'))
    }
    emit('update:modelValue', false)
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<ResolutionField>(err)
    }
    if (Object.keys(fieldErrors.value).length === 0) {
      actionError.value = t('app.campaigns.resolution.toast.error')
    }
  } finally {
    submitting.value = null
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="720"
    scrollable
    data-test="resolve-verification-drawer"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="d-flex align-center">
        <span class="text-h6">{{ t('app.campaigns.resolution.title') }}</span>
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
          data-test="resolution-close"
          @click="close"
        />
      </v-card-title>

      <v-divider />

      <v-card-text>
        <v-skeleton-loader v-if="loading" type="article" data-test="resolution-skeleton" />

        <v-alert
          v-else-if="loadError"
          type="error"
          variant="tonal"
          data-test="resolution-load-error"
        >
          {{ loadError }}
        </v-alert>

        <template v-else>
          <!-- The failed post + its verification reason -->
          <v-card v-if="latestPost" variant="outlined" class="mb-4" data-test="resolution-posted">
            <v-card-text>
              <div class="text-subtitle-2 mb-1">
                {{ t('app.campaigns.resolution.postedContent') }}
              </div>
              <a
                :href="latestPost.attributes.post_url"
                target="_blank"
                rel="noopener noreferrer"
                class="text-body-2"
                data-test="resolution-post-url"
              >
                {{ latestPost.attributes.post_url }}
              </a>
              <div class="d-flex align-center ga-2 flex-wrap mt-2">
                <span class="text-caption text-medium-emphasis">{{
                  latestPost.attributes.platform
                }}</span>
                <v-chip
                  size="x-small"
                  color="error"
                  variant="tonal"
                  data-test="resolution-verification-status"
                >
                  {{
                    t(
                      `app.campaigns.resolution.verification.${latestPost.attributes.verification_status}`,
                    )
                  }}
                </v-chip>
                <span class="text-caption text-medium-emphasis">{{
                  t('app.campaigns.resolution.simulated')
                }}</span>
              </div>
            </v-card-text>
          </v-card>

          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('app.campaigns.resolution.intro') }}
          </p>

          <template v-if="canAct">
            <v-alert
              v-if="actionError"
              type="error"
              variant="tonal"
              class="mb-3"
              data-test="resolution-action-error"
            >
              {{ actionError }}
            </v-alert>
            <v-textarea
              v-model="note"
              :label="t('app.campaigns.resolution.noteLabel')"
              :hint="t('app.campaigns.resolution.noteHint')"
              persistent-hint
              variant="outlined"
              rows="3"
              auto-grow
              :error-messages="fieldErrors.reason as string[]"
              data-test="resolution-note"
            />
          </template>

          <v-alert v-else type="info" variant="tonal" data-test="resolution-not-actionable">
            {{ t('app.campaigns.resolution.notActionable') }}
          </v-alert>
        </template>
      </v-card-text>

      <v-divider />

      <v-card-actions v-if="canAct" class="flex-wrap">
        <v-btn variant="text" data-test="resolution-cancel" @click="close">
          {{ t('app.campaigns.resolution.close') }}
        </v-btn>
        <v-spacer />
        <v-btn
          color="secondary"
          variant="text"
          :loading="submitting === 'in_place'"
          :disabled="submitting !== null"
          data-test="resolution-request-in-place"
          @click="runAction('in_place')"
        >
          {{ t('app.campaigns.resolution.requestInPlace') }}
        </v-btn>
        <v-btn
          color="warning"
          variant="tonal"
          :loading="submitting === 'fresh'"
          :disabled="submitting !== null"
          data-test="resolution-request-fresh"
          @click="runAction('fresh')"
        >
          {{ t('app.campaigns.resolution.requestFresh') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="submitting === 'verify'"
          :disabled="submitting !== null"
          data-test="resolution-manually-verify"
          @click="runAction('verify')"
        >
          {{ t('app.campaigns.resolution.manuallyVerify') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
