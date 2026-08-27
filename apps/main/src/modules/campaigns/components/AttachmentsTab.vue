<script setup lang="ts">
/**
 * Campaign attachments from the Files library, chunk B (AH-092) — the
 * campaign-detail Attachments tab (S6). Fetch-on-open, mirroring
 * `DraftsTab`/`ApplicationsTab`.
 *
 * Every agency member reads (`view`-gated, mirroring `listApplications`);
 * attach/toggle/detach are `update`-gated — `canEdit` on this component,
 * the same admin/manager ability `CampaignDetailPage` already computes for
 * the Settings tab (D3's ratified authorization split: campaign management
 * acts under the campaign's own gate, not a new dedicated policy class).
 *
 * The visibility switch writes THROUGH the server on every flip (no local
 * optimistic toggle-then-revert dance) — a campaign's attachment count is
 * small, so the extra round-trip per toggle is cheap, and it keeps the row's
 * displayed state always equal to what the server actually holds.
 */

import { ApiError, formatDateTime, formatFileSize } from '@catalyst/api-client'
import type { CampaignAttachmentResource } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import { campaignsApi } from '../api/campaigns.api'
import AttachFilesDialog from './AttachFilesDialog.vue'

const props = defineProps<{
  agencyId: string
  campaignId: string
  /** The `update` ability (D3) — admin/manager only, mirroring Settings. */
  canEdit: boolean
}>()

const { t, locale } = useI18n()

const attachments = ref<CampaignAttachmentResource[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)

const attachedFileIds = computed(() => attachments.value.map((a) => a.id))

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await campaignsApi.listAttachments(props.agencyId, props.campaignId)
    attachments.value = res.data
  } catch {
    loadError.value = t('app.campaigns.attachments.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

// ── Attach picker ───────────────────────────────────────────────────────
const pickerDialog = ref(false)
const successSnackbar = ref<string | null>(null)

function onAttached(message: string): void {
  successSnackbar.value = message
  void load()
}

// ── Visibility toggle ───────────────────────────────────────────────────
const togglingId = ref<string | null>(null)
const toggleError = ref<string | null>(null)

async function toggleVisibility(row: CampaignAttachmentResource): Promise<void> {
  togglingId.value = row.id
  toggleError.value = null
  try {
    await campaignsApi.updateAttachment(props.agencyId, props.campaignId, row.id, {
      visible_to_creators: !row.attributes.visible_to_creators,
    })
    await load()
  } catch {
    toggleError.value = t('app.campaigns.attachments.errors.toggleFailed')
  } finally {
    togglingId.value = null
  }
}

// ── Detach ───────────────────────────────────────────────────────────────
const detachDialog = ref(false)
const detachTarget = ref<CampaignAttachmentResource | null>(null)
const detaching = ref(false)
const detachError = ref<string | null>(null)

function openDetach(row: CampaignAttachmentResource): void {
  detachTarget.value = row
  detachDialog.value = true
  detachError.value = null
}

function closeDetach(): void {
  detachDialog.value = false
  detachTarget.value = null
  detachError.value = null
}

async function confirmDetach(): Promise<void> {
  if (detachTarget.value === null) return
  detaching.value = true
  detachError.value = null
  try {
    await campaignsApi.detachFile(props.agencyId, props.campaignId, detachTarget.value.id)
    closeDetach()
    void load()
  } catch (err) {
    detachError.value =
      err instanceof ApiError
        ? `[${err.code}] ${err.message}`
        : t('app.campaigns.attachments.errors.detachFailed')
  } finally {
    detaching.value = false
  }
}

function formatAttachedAt(iso: string): string {
  return formatDateTime(iso, locale.value)
}

defineExpose({
  reload: (): Promise<void> => load(),
})
</script>

<template>
  <div class="attachments-tab" data-test="attachments-tab">
    <div class="d-flex justify-end mb-3">
      <v-btn
        v-if="canEdit"
        color="primary"
        variant="flat"
        prepend-icon="mdi-paperclip"
        data-test="attachments-open-picker"
        @click="pickerDialog = true"
      >
        {{ t('app.campaigns.attachments.actions.attach') }}
      </v-btn>
    </div>

    <v-alert
      v-if="toggleError"
      type="error"
      variant="tonal"
      density="compact"
      class="mb-3"
      data-test="attachments-toggle-error"
    >
      {{ toggleError }}
    </v-alert>

    <v-skeleton-loader
      v-if="loading"
      type="list-item-two-line@3"
      data-test="attachments-skeleton"
    />

    <v-alert
      v-else-if="loadError"
      type="error"
      variant="tonal"
      density="compact"
      data-test="attachments-load-error"
    >
      {{ loadError }}
    </v-alert>

    <CEmptyState
      v-else-if="attachments.length === 0"
      data-test="attachments-empty-state"
      title-tag="h2"
      :title="t('app.campaigns.attachments.empty.heading')"
      :body="t('app.campaigns.attachments.empty.body')"
    >
      <template #icon>
        <v-icon icon="mdi-paperclip" size="56" color="medium-emphasis" />
      </template>
    </CEmptyState>

    <v-list v-else lines="two" data-test="attachments-list">
      <v-list-item
        v-for="row in attachments"
        :key="row.id"
        :data-test="`attachments-row-${row.id}`"
      >
        <template #prepend>
          <v-icon icon="mdi-file-outline" size="20" color="medium-emphasis" class="mr-3" />
        </template>

        <v-list-item-title :data-test="`attachments-name-${row.id}`">
          {{ row.attributes.original_filename }}
        </v-list-item-title>
        <v-list-item-subtitle :data-test="`attachments-meta-${row.id}`">
          {{ formatFileSize(row.attributes.size_bytes) }}
          <template v-if="row.relationships.attached_by">
            · {{ t('app.campaigns.attachments.fields.attachedBy') }}:
            {{ row.relationships.attached_by.data.attributes.name }}
          </template>
          · {{ formatAttachedAt(row.attributes.attached_at) }}
        </v-list-item-subtitle>

        <template #append>
          <div class="d-flex align-center ga-3">
            <v-switch
              :model-value="row.attributes.visible_to_creators"
              :disabled="!canEdit || togglingId === row.id"
              :label="t('app.campaigns.attachments.fields.visibleToCreators')"
              color="primary"
              density="compact"
              hide-details
              :data-test="`attachments-visible-toggle-${row.id}`"
              @update:model-value="toggleVisibility(row)"
            />
            <v-btn
              icon="mdi-download-outline"
              size="small"
              variant="text"
              :disabled="row.attributes.download_url === null"
              :href="row.attributes.download_url ?? undefined"
              target="_blank"
              rel="noopener noreferrer"
              :aria-label="t('app.files.actions.download')"
              :data-test="`attachments-download-${row.id}`"
            />
            <v-btn
              v-if="canEdit"
              icon="mdi-link-off"
              size="small"
              variant="text"
              color="error"
              :aria-label="t('app.campaigns.attachments.detach.action')"
              :data-test="`attachments-detach-${row.id}`"
              @click="openDetach(row)"
            />
          </div>
        </template>
      </v-list-item>
    </v-list>

    <AttachFilesDialog
      v-if="canEdit"
      v-model="pickerDialog"
      :agency-id="agencyId"
      :campaign-id="campaignId"
      :attached-file-ids="attachedFileIds"
      @attached="onAttached"
    />

    <v-dialog v-model="detachDialog" max-width="440" data-test="attachments-detach-dialog">
      <v-card v-if="detachTarget">
        <v-card-title class="text-h6 pa-4" data-test="attachments-detach-dialog-title">
          {{ t('app.campaigns.attachments.detach.confirmTitle') }}
        </v-card-title>
        <v-card-text>
          <p data-test="attachments-detach-dialog-message">
            {{
              t('app.campaigns.attachments.detach.confirmMessage', {
                name: detachTarget.attributes.original_filename,
              })
            }}
          </p>
          <v-alert
            v-if="detachError"
            type="error"
            variant="tonal"
            class="mt-2"
            data-test="attachments-detach-dialog-error"
          >
            {{ detachError }}
          </v-alert>
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn
            variant="text"
            :disabled="detaching"
            data-test="attachments-detach-dialog-cancel"
            @click="closeDetach"
          >
            {{ t('app.campaigns.attachments.detach.cancel') }}
          </v-btn>
          <v-btn
            color="error"
            variant="flat"
            :loading="detaching"
            :disabled="detaching"
            data-test="attachments-detach-dialog-confirm"
            @click="confirmDetach"
          >
            {{ t('app.campaigns.attachments.detach.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar
      :model-value="successSnackbar !== null"
      :timeout="4000"
      color="success"
      data-test="attachments-success-snackbar"
      @update:model-value="
        (v) => {
          if (!v) successSnackbar = null
        }
      "
    >
      {{ successSnackbar }}
    </v-snackbar>
  </div>
</template>
