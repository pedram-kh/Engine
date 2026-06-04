<script setup lang="ts">
/**
 * Blacklist-a-creator dialog (Sprint 7, A7). Opened from the 2a creator detail
 * page header (admin/manager only — the same `canEdit` floor as rating/notes).
 *
 * Captures the mandatory reason (D-7), the scope (agency-wide vs brand-scoped,
 * D-2 — the brand picker appears only for brand scope), and the hard/soft type
 * (D-1). Submits to the dedicated blacklist endpoint (NOT the rating/notes
 * PATCH — D-2 no dual-write). The dialog shell mirrors AddToPoolDialog; the
 * brand options reuse the PoolCreatePage `brandsApi.list` pattern (no shared
 * brand-picker component exists).
 */

import type { BlacklistScope, BlacklistType } from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandsApi } from '@/modules/brands/api/brands.api'

import { rosterApi } from '../api/roster.api'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  creatorUlid: string
  /** True when the creator has no relation with this agency (discovery-only);
   *  agency-wide blacklist requires a relation, so it is disabled then. */
  hasRelation: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  blacklisted: [message: string]
}>()

const { t } = useI18n()

const scope = ref<BlacklistScope>('agency')
const type = ref<BlacklistType>('hard')
const reason = ref<string>('')
const brandId = ref<string | null>(null)

const submitting = ref(false)
const error = ref<string | null>(null)

const brandOptions = ref<Array<{ value: string; title: string }>>([])
const brandsLoading = ref(false)

const reasonValid = computed(() => reason.value.trim().length > 0)
const brandValid = computed(() => scope.value !== 'brand' || brandId.value !== null)
const canSubmit = computed(() => reasonValid.value && brandValid.value && !submitting.value)

function reset(): void {
  scope.value = props.hasRelation ? 'agency' : 'brand'
  type.value = 'hard'
  reason.value = ''
  brandId.value = null
  error.value = null
}

async function loadBrands(): Promise<void> {
  brandsLoading.value = true
  try {
    const res = await brandsApi.list(props.agencyId, { per_page: 100, status: 'active' })
    brandOptions.value = res.data.map((b) => ({ value: b.id, title: b.attributes.name }))
  } catch {
    brandOptions.value = []
  } finally {
    brandsLoading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      reset()
      void loadBrands()
    }
  },
  { immediate: true },
)

function close(): void {
  emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  submitting.value = true
  error.value = null
  try {
    await rosterApi.blacklist(props.agencyId, props.creatorUlid, {
      scope: scope.value,
      type: type.value,
      reason: reason.value.trim(),
      ...(scope.value === 'brand' && brandId.value !== null ? { brand_id: brandId.value } : {}),
    })
    emit('blacklisted', t('app.roster.blacklist.dialog.success'))
    close()
  } catch (e) {
    error.value =
      e instanceof ApiError && e.status === 422
        ? t('app.roster.blacklist.dialog.invalid')
        : t('app.roster.blacklist.dialog.failed')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    data-test="blacklist-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6 pa-4 d-flex align-center justify-space-between">
        {{ t('app.roster.blacklist.dialog.title') }}
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="blacklist-close"
          @click="close"
        />
      </v-card-title>

      <v-card-text>
        <v-alert v-if="error" type="error" variant="tonal" class="mb-3" data-test="blacklist-error">
          {{ error }}
        </v-alert>

        <!-- Scope (D-2): agency-wide vs brand-scoped -->
        <v-radio-group
          v-model="scope"
          :label="t('app.roster.blacklist.dialog.scopeLabel')"
          density="compact"
          data-test="blacklist-scope"
        >
          <v-radio
            :label="t('app.roster.blacklist.dialog.scope.agency')"
            value="agency"
            :disabled="!hasRelation"
            data-test="blacklist-scope-agency"
          />
          <v-radio
            :label="t('app.roster.blacklist.dialog.scope.brand')"
            value="brand"
            data-test="blacklist-scope-brand"
          />
        </v-radio-group>

        <p
          v-if="!hasRelation"
          class="text-caption text-medium-emphasis mb-2"
          data-test="blacklist-no-relation-hint"
        >
          {{ t('app.roster.blacklist.dialog.noRelationHint') }}
        </p>

        <!-- Brand picker (brand scope only) -->
        <v-select
          v-if="scope === 'brand'"
          v-model="brandId"
          :label="t('app.roster.blacklist.dialog.brandLabel')"
          :items="brandOptions"
          :loading="brandsLoading"
          item-title="title"
          item-value="value"
          data-test="blacklist-brand"
        />

        <!-- Type (D-1): hard = exclude, soft = warn only -->
        <v-radio-group
          v-model="type"
          :label="t('app.roster.blacklist.dialog.typeLabel')"
          density="compact"
          data-test="blacklist-type"
        >
          <v-radio
            :label="t('app.roster.blacklist.dialog.type.hard')"
            value="hard"
            data-test="blacklist-type-hard"
          />
          <v-radio
            :label="t('app.roster.blacklist.dialog.type.soft')"
            value="soft"
            data-test="blacklist-type-soft"
          />
        </v-radio-group>

        <!-- Mandatory reason (D-7) -->
        <v-textarea
          v-model="reason"
          :label="t('app.roster.blacklist.dialog.reasonLabel')"
          :placeholder="t('app.roster.blacklist.dialog.reasonPlaceholder')"
          variant="outlined"
          rows="3"
          auto-grow
          counter="5000"
          maxlength="5000"
          hide-details="auto"
          data-test="blacklist-reason"
        />
      </v-card-text>

      <v-card-actions class="px-4 pb-4">
        <v-spacer />
        <v-btn variant="text" data-test="blacklist-cancel" @click="close">
          {{ t('app.roster.blacklist.dialog.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          variant="flat"
          :loading="submitting"
          :disabled="!canSubmit"
          data-test="blacklist-submit"
          @click="submit"
        >
          {{ t('app.roster.blacklist.dialog.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
