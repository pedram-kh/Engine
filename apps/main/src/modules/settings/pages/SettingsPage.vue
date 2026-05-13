<script setup lang="ts">
/**
 * Agency settings page — currency + language form.
 *
 * Visible to all roles; editable by agency_admin only.
 * Non-admin users see read-only fields with an informational note.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { storeToRefs } from 'pinia'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { settingsApi } from '../api/settings.api'

const { t } = useI18n()
const agencyStore = useAgencyStore()
const { isAdmin, currentAgencyId } = storeToRefs(agencyStore)

const defaultCurrency = ref<string>('')
const defaultLanguage = ref<string>('')
const loading = ref(true)
const saving = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const saveSuccess = ref(false)

const currencyOptions = [
  { title: 'USD — US Dollar', value: 'USD' },
  { title: 'EUR — Euro', value: 'EUR' },
  { title: 'BRL — Brazilian Real', value: 'BRL' },
  { title: 'GBP — British Pound', value: 'GBP' },
  { title: 'AUD — Australian Dollar', value: 'AUD' },
  { title: 'CAD — Canadian Dollar', value: 'CAD' },
  { title: 'JPY — Japanese Yen', value: 'JPY' },
]

const languageOptions = [
  { title: t('app.locale.en'), value: 'en' },
  { title: t('app.locale.pt'), value: 'pt' },
  { title: t('app.locale.it'), value: 'it' },
]

async function loadSettings(): Promise<void> {
  const agencyId = currentAgencyId.value
  if (agencyId === null) return

  loading.value = true
  loadError.value = null
  try {
    const res = await settingsApi.show(agencyId)
    defaultCurrency.value = res.data.attributes.default_currency
    defaultLanguage.value = res.data.attributes.default_language
  } catch {
    loadError.value = t('app.settings.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

async function onSave(): Promise<void> {
  const agencyId = currentAgencyId.value
  if (agencyId === null || !isAdmin.value) return

  saving.value = true
  saveError.value = null
  saveSuccess.value = false
  try {
    await settingsApi.update(agencyId, {
      default_currency: defaultCurrency.value,
      default_language: defaultLanguage.value,
    })
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 4000)
  } catch {
    saveError.value = t('app.settings.errors.saveFailed')
  } finally {
    saving.value = false
  }
}

onMounted(loadSettings)
</script>

<template>
  <div data-test="settings-page">
    <h1 class="text-h5 mb-6" data-test="settings-heading">{{ t('app.settings.title') }}</h1>

    <!-- Loading skeleton -->
    <v-skeleton-loader v-if="loading" type="article" data-test="settings-skeleton" />

    <!-- Load error -->
    <v-alert v-else-if="loadError" type="error" variant="tonal" data-test="settings-load-error">
      {{ loadError }}
    </v-alert>

    <!-- Settings form -->
    <v-card v-else class="pa-6" max-width="560">
      <!-- Read-only notice for non-admins -->
      <v-alert
        v-if="!isAdmin"
        type="info"
        variant="tonal"
        class="mb-4"
        data-test="settings-readonly-notice"
      >
        {{ t('app.settings.readOnly') }}
      </v-alert>

      <!-- Success toast -->
      <v-alert
        v-if="saveSuccess"
        type="success"
        variant="tonal"
        class="mb-4"
        closable
        data-test="settings-success"
        @click:close="saveSuccess = false"
      >
        {{ t('app.settings.success') }}
      </v-alert>

      <form novalidate data-test="settings-form" @submit.prevent="onSave">
        <v-select
          v-model="defaultCurrency"
          :label="t('app.settings.fields.defaultCurrency')"
          :items="currencyOptions"
          item-title="title"
          item-value="value"
          :readonly="!isAdmin"
          :disabled="!isAdmin"
          data-test="settings-currency"
        />

        <v-select
          v-model="defaultLanguage"
          :label="t('app.settings.fields.defaultLanguage')"
          :items="languageOptions"
          item-title="title"
          item-value="value"
          :readonly="!isAdmin"
          :disabled="!isAdmin"
          data-test="settings-language"
        />

        <div
          v-if="saveError"
          role="alert"
          aria-live="polite"
          class="text-error text-body-2 mb-3"
          data-test="settings-save-error"
        >
          {{ saveError }}
        </div>

        <div class="d-flex justify-end">
          <v-btn
            v-if="isAdmin"
            type="submit"
            color="primary"
            :loading="saving"
            :disabled="saving"
            data-test="settings-save-btn"
          >
            {{ saving ? t('app.settings.saving') : t('app.settings.save') }}
          </v-btn>
        </div>
      </form>
    </v-card>
  </div>
</template>
