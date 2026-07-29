<script setup lang="ts">
/**
 * Reject an application, confirmed (AH-059, S7a — extracted from ApplicationsTab).
 *
 * The terminal-action confirmation that used to live inline in the Applications
 * tab. It is a component now for one reason: the board's Applications column
 * (D4) offers the same two answers on the same rows, and the second consumer is
 * exactly the moment an inline dialog stops being an implementation detail. The
 * precedent is chunk 4's {@link OfferFieldsForm}, extracted when
 * AcceptApplicationDialog became its second caller.
 *
 * ── No reason is collected, deliberately ────────────────────────────────────
 *
 * The creator-facing copy is a kind, generic "not selected" whatever an agency
 * might type, so a free-text box would collect something nobody reads and imply
 * it travels. The audit row and its actor are the internal record. Carried over
 * from D4 unchanged.
 *
 * ── Refusals surface, they never close silently ─────────────────────────────
 *
 * The common one is someone else having answered this application first (§5.6),
 * and the dialog's own body is the wrong place to leave that: the row it refers
 * to is in the list behind it, and the list is about to refetch. So the message
 * is EMITTED and the host renders it in its own error slot — which is also what
 * keeps the tab's existing refusal assertion working through the extraction.
 *
 * ⚠ The `data-test` hooks (`reject-application-dialog` / `-body` / `-cancel` /
 * `-confirm`) are the tab's from before the extraction, kept identical on
 * purpose: this refactor was required to move a component without moving a
 * single assertion.
 */

import { ApiError } from '@catalyst/api-client'
import type { CampaignApplicationListItemResource } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  application: CampaignApplicationListItemResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  /** Answered. The host toasts this and refetches. */
  rejected: [message: string]
  /** Refused. The host renders this where the operator is already looking. */
  refused: [message: string]
}>()

const { t } = useI18n()

const submitting = ref(false)

const creatorName = computed(
  () => props.application?.attributes.creator?.display_name ?? t('app.campaigns.invite.unnamed'),
)

/**
 * The refusal copy, keyed off the server's `meta.code` — the
 * AcceptApplicationDialog mapping, narrowed to the one code this path can
 * produce. An unknown code falls through to the generic message rather than
 * rendering a raw code at an operator.
 */
function refusalMessage(err: unknown): string {
  const code =
    err instanceof ApiError ? (err.raw as { meta?: { code?: string } } | null)?.meta?.code : null

  return code === 'application.not_pending'
    ? t('app.campaigns.applications.refusal.application.not_pending')
    : t('app.campaigns.applications.refusal.generic')
}

async function confirm(): Promise<void> {
  const application = props.application
  if (application === null || submitting.value) return

  submitting.value = true

  try {
    await campaignsApi.rejectApplication(props.agencyId, props.campaignId, application.id)
    emit('update:modelValue', false)
    emit('rejected', t('app.campaigns.applications.rejectedToast', { name: creatorName.value }))
  } catch (err) {
    emit('refused', refusalMessage(err))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="420"
    data-test="reject-application-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6">
        {{ t('app.campaigns.applications.reject.title') }}
      </v-card-title>
      <v-card-text data-test="reject-application-body">
        {{ t('app.campaigns.applications.reject.body', { name: creatorName }) }}
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          data-test="reject-application-cancel"
          @click="emit('update:modelValue', false)"
        >
          {{ t('app.campaigns.applications.reject.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          variant="flat"
          :loading="submitting"
          data-test="reject-application-confirm"
          @click="confirm"
        >
          {{ t('app.campaigns.applications.reject.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
