<script setup lang="ts">
/**
 * The offer fields an agency fills in whenever it puts an offer in front of a
 * creator: the fee (major units in, minor units on the wire), the free-text
 * "per" unit, the expectations text, and ONE optional attachment uploaded
 * through the campaign-keyed presigned pair.
 *
 * ── Why this is a shared child (AH-058, Q2) ─────────────────────────────────
 *
 * Two dialogs now make offers: `InviteCreatorsDialog` (a cold invite, looped
 * over a selection) and `AcceptApplicationDialog` (accepting an application —
 * the same offer, minus the picker). Duplicating the fee/currency/attachment
 * handling is the version that drifts: the copy that gets the next validation
 * fix is whichever one the next author happened to open. So the fields, their
 * validity rule and the upload live here, and each parent owns only its own
 * submission flow.
 *
 * The parent drives submission through the two exposed methods rather than by
 * reading state: `buildOffer()` returns the wire payload (uploading the
 * attachment exactly once, cached across retries such as the availability
 * acknowledge pass) or `null` when the upload failed, in which case the error is
 * already rendered here.
 *
 * `testPrefix` keeps each parent's existing `data-test` ids intact — the
 * refactor moved the markup, and a selector rename would have made an
 * unnecessary behavioural claim about it.
 */

import { uploadToPresignedUrl } from '@catalyst/api-client'
import type { InviteAssignmentPayload } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { VTextarea } from 'vuetify/components'

import { campaignsApi } from '../api/campaigns.api'
import InsertLinkButton, { type TextareaHandle } from './InsertLinkButton.vue'

/** The offer subset both callers send — the invite payload without the target. */
type OfferFields = Omit<InviteAssignmentPayload, 'creator_id' | 'acknowledged'>

const props = withDefaults(
  defineProps<{
    agencyId: string
    campaignId: string
    /** The campaign's currency; shown read-only, sent as-is. */
    currency: string
    testPrefix?: string
    /** Server-side 422 messages for the fee, bound per-field by the parent. */
    feeErrors?: readonly string[]
  }>(),
  { testPrefix: 'offer-fields', feeErrors: () => [] },
)

const emit = defineEmits<{
  'update:valid': [value: boolean]
}>()

const { t } = useI18n()

const feeAmount = ref<number | null>(null)
const feePer = ref('')
const offerDescription = ref('')
const attachmentFile = ref<File | null>(null)
const uploadError = ref<string | null>(null)

// Handed to InsertLinkButton for selection reads + cursor restore — see that
// component's doc comment for why the cast is safe (VTextarea forwards the
// native textarea's selection API dynamically, outside its public TS type).
// The cast is hoisted into a computed (rather than inline in the template)
// because eslint-plugin-vue's `no-deprecated-filter` rule misreads a `|`
// union type inside a template expression as a Vue 2 filter pipe.
const offerDescriptionTextarea = ref<InstanceType<typeof VTextarea> | null>(null)
const offerDescriptionTextareaHandle = computed<TextareaHandle | null>(
  () => offerDescriptionTextarea.value as unknown as TextareaHandle | null,
)

// The completed presigned upload, shared by every send of this form's payload
// (the invite loop, and the TIER-2 acknowledge pass) — uploaded exactly once.
const uploadedAttachment = ref<InviteAssignmentPayload['attachment']>(null)

const valid = computed(() => feeAmount.value !== null && feeAmount.value > 0)

watch(valid, (isValid) => emit('update:valid', isValid), { immediate: true })

// A new file invalidates the cached upload — otherwise swapping the attachment
// after a failed submit would silently send the previous file.
watch(attachmentFile, () => {
  uploadedAttachment.value = null
  uploadError.value = null
})

function reset(): void {
  feeAmount.value = null
  feePer.value = ''
  offerDescription.value = ''
  attachmentFile.value = null
  uploadedAttachment.value = null
  uploadError.value = null
}

async function uploadAttachment(): Promise<boolean> {
  const file = attachmentFile.value
  if (file === null) {
    uploadedAttachment.value = null
    return true
  }
  if (uploadedAttachment.value !== null) return true

  try {
    const init = await campaignsApi.offerAttachmentInit(props.agencyId, props.campaignId, {
      mime_type: file.type,
      size_bytes: file.size,
    })
    await uploadToPresignedUrl(init.data.upload_url, file, { contentType: file.type })
    const complete = await campaignsApi.offerAttachmentComplete(props.agencyId, props.campaignId, {
      upload_id: init.data.upload_id,
    })
    uploadedAttachment.value = {
      upload_id: complete.data.storage_path,
      name: file.name,
      mime_type: file.type,
      size_bytes: file.size,
    }
    return true
  } catch {
    uploadError.value = t('app.campaigns.invite.attachmentFailed')
    return false
  }
}

/** Null when the attachment upload failed — never a half-attached offer. */
async function buildOffer(): Promise<OfferFields | null> {
  if (!(await uploadAttachment())) return null

  return {
    agreed_fee_minor_units: Math.round((feeAmount.value ?? 0) * 100),
    agreed_fee_currency: props.currency,
    fee_per: feePer.value.trim() === '' ? null : feePer.value.trim(),
    offer_description: offerDescription.value.trim() === '' ? null : offerDescription.value.trim(),
    attachment: uploadedAttachment.value,
  }
}

defineExpose({ buildOffer, reset, valid })
</script>

<template>
  <div :data-test="`${testPrefix}-form`">
    <v-alert
      v-if="uploadError"
      type="error"
      variant="tonal"
      density="compact"
      class="mb-3"
      :data-test="`${testPrefix}-upload-error`"
    >
      {{ uploadError }}
    </v-alert>

    <!-- The agreed fee (D-8), with the free-text "Per" unit beside it. -->
    <div class="d-flex ga-2 mb-3">
      <v-text-field
        v-model.number="feeAmount"
        type="number"
        min="0"
        step="0.01"
        density="compact"
        variant="outlined"
        class="flex-1-1-0"
        :hide-details="feeErrors.length === 0"
        :label="t('app.campaigns.invite.feeLabel', { currency })"
        :suffix="currency"
        :error-messages="feeErrors as string[]"
        :data-test="`${testPrefix}-fee`"
      />
      <v-text-field
        v-model="feePer"
        density="compact"
        variant="outlined"
        hide-details
        class="flex-1-1-0"
        :label="t('app.campaigns.invite.perLabel')"
        :placeholder="t('app.campaigns.invite.perPlaceholder')"
        maxlength="120"
        :data-test="`${testPrefix}-fee-per`"
      />
    </div>

    <!-- Free-text expectations, shown to the creator with the invitation.
         Minimal rich text (AH-081): rendered through RichBrief at every
         creator-facing site, hence the formatting hint below. -->
    <v-textarea
      ref="offerDescriptionTextarea"
      v-model="offerDescription"
      density="compact"
      variant="outlined"
      rows="2"
      auto-grow
      class="mb-3"
      :label="t('app.campaigns.invite.descriptionLabel')"
      :hint="t('app.campaigns.invite.formattingHint')"
      persistent-hint
      maxlength="3000"
      :data-test="`${testPrefix}-description`"
    >
      <template #append-inner>
        <InsertLinkButton
          v-model="offerDescription"
          :textarea-ref="offerDescriptionTextareaHandle"
          :maxlength="3000"
          :test-prefix="`${testPrefix}-insert-link`"
        />
      </template>
    </v-textarea>

    <!-- ONE optional offer attachment (brief / reference file). -->
    <v-file-input
      v-model="attachmentFile"
      density="compact"
      variant="outlined"
      class="mb-3"
      hide-details
      prepend-icon=""
      prepend-inner-icon="mdi-paperclip"
      :label="t('app.campaigns.invite.attachmentLabel')"
      :data-test="`${testPrefix}-attachment`"
    />
  </div>
</template>
