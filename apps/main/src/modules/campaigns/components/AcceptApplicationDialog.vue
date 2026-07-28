<script setup lang="ts">
/**
 * Accept an application with a real offer (Jobs Board chunk 4, D2/Q2).
 *
 * The ReinviteDialog shape — one narrow dialog, per-field 422 binding, close on
 * success — wrapped around the shared {@link OfferFieldsForm}. What the agency
 * fills in here is the same offer a cold invite carries, because that is exactly
 * what accepting produces: a standard invitation the creator still answers.
 * Applying is interest, not consent to terms, so the copy says "send offer"
 * rather than "hire".
 *
 * ── The two gate tiers, same severities as the invite path ──────────────────
 *
 *   - 409 `assignment.availability_conflict` (SOFT WARN) — the creator is busy
 *     over the campaign window. Shown as a proceed-anyway prompt; proceeding
 *     re-sends the identical payload with `acknowledged: true`.
 *   - 422 (HARD) — a blacklist added after the application, an application
 *     someone else already answered, or a creator already engaged on this
 *     campaign. Surfaced as a message with the server's code, never swallowed:
 *     a dialog that closes silently on a refusal teaches the operator that the
 *     button does nothing.
 */

import { ApiError, extractFieldErrors } from '@catalyst/api-client'
import type { CampaignApplicationListItemResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'
import OfferFieldsForm from './OfferFieldsForm.vue'

type FeeField = 'agreed_fee_minor_units' | 'agreed_fee_currency'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  application: CampaignApplicationListItemResource | null
  campaignCurrency: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  accepted: [message: string]
}>()

const { t } = useI18n()

const offerFields = ref<InstanceType<typeof OfferFieldsForm> | null>(null)
const offerValid = ref(false)
const submitting = ref(false)
const fieldErrors = ref<Partial<Record<FeeField, readonly string[]>>>({})
const refusal = ref<string | null>(null)
const conflictPrompt = ref(false)

const currency = computed(() => props.campaignCurrency ?? 'EUR')
const creatorName = computed(
  () => props.application?.attributes.creator?.display_name ?? t('app.campaigns.invite.unnamed'),
)
const note = computed(() => props.application?.attributes.note ?? null)
const canSubmit = computed(() => offerValid.value && !submitting.value)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      offerFields.value?.reset()
      fieldErrors.value = {}
      refusal.value = null
      conflictPrompt.value = false
    }
  },
)

function close(): void {
  emit('update:modelValue', false)
}

/**
 * The refusal copy, keyed off the server's `meta.code` so each 422 says which
 * of the four different things went wrong.
 */
function refusalMessage(err: ApiError): string {
  const code = (err.raw as { meta?: { code?: string } } | null)?.meta?.code
  const known = [
    'application.not_pending',
    'application.already_engaged',
    'application.creator_not_approved',
    'assignment.blacklisted',
  ]

  return known.includes(code ?? '')
    ? t(`app.campaigns.applications.refusal.${code}`)
    : t('app.campaigns.applications.refusal.generic')
}

async function send(acknowledged: boolean): Promise<void> {
  const application = props.application
  if (application === null || !canSubmit.value) return

  submitting.value = true
  fieldErrors.value = {}
  refusal.value = null

  const offer = await offerFields.value?.buildOffer()
  if (offer === null || offer === undefined) {
    submitting.value = false
    return
  }

  try {
    await campaignsApi.acceptApplication(props.agencyId, props.campaignId, application.id, {
      ...offer,
      acknowledged,
    })
    emit('accepted', t('app.campaigns.applications.acceptedToast', { name: creatorName.value }))
    emit('update:modelValue', false)
  } catch (err) {
    if (err instanceof ApiError && err.status === 409) {
      // TIER 2 — the agency decides, holding the payload it already built.
      conflictPrompt.value = true
    } else if (err instanceof ApiError && err.status === 422) {
      fieldErrors.value = extractFieldErrors<FeeField>(err)
      if (Object.keys(fieldErrors.value).length === 0) {
        refusal.value = refusalMessage(err)
      }
    } else {
      refusal.value = t('app.campaigns.applications.refusal.generic')
    }
  } finally {
    submitting.value = false
  }
}

function proceedWithConflict(): void {
  conflictPrompt.value = false
  void send(true)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    data-test="accept-application-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6">
        {{ t('app.campaigns.applications.accept.title', { name: creatorName }) }}
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 text-medium-emphasis mb-3" data-test="accept-application-body">
          {{ t('app.campaigns.applications.accept.body') }}
        </p>

        <v-alert
          v-if="note"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-3"
          data-test="accept-application-note"
        >
          {{ note }}
        </v-alert>

        <v-alert
          v-if="refusal"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-3"
          data-test="accept-application-refusal"
        >
          {{ refusal }}
        </v-alert>

        <OfferFieldsForm
          ref="offerFields"
          :agency-id="agencyId"
          :campaign-id="campaignId"
          :currency="currency"
          test-prefix="accept-application"
          :fee-errors="fieldErrors.agreed_fee_minor_units ?? []"
          @update:valid="(v) => (offerValid = v)"
        />
      </v-card-text>

      <v-card-actions class="px-4 pb-4">
        <v-spacer />
        <v-btn variant="text" data-test="accept-application-cancel" @click="close">
          {{ t('app.campaigns.applications.accept.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!canSubmit"
          :loading="submitting"
          data-test="accept-application-submit"
          @click="send(false)"
        >
          {{ t('app.campaigns.applications.accept.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <!-- TIER 2 — the availability proceed-anyway prompt (the invite path's UX). -->
    <v-dialog v-model="conflictPrompt" max-width="420" data-test="accept-availability-warning">
      <v-card>
        <v-card-title class="text-h6">
          {{ t('app.campaigns.invite.conflict.title') }}
        </v-card-title>
        <v-card-text>
          {{ t('app.campaigns.applications.accept.conflictBody', { name: creatorName }) }}
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn
            variant="text"
            data-test="accept-availability-cancel"
            @click="conflictPrompt = false"
          >
            {{ t('app.campaigns.invite.conflict.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            data-test="accept-availability-proceed"
            @click="proceedWithConflict"
          >
            {{ t('app.campaigns.invite.conflict.proceed') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>
