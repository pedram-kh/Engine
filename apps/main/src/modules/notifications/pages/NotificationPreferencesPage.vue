<script setup lang="ts">
/**
 * NotificationPreferencesPage — the per-user notification settings surface
 * (S11.0 Ch3b). The product's first user self-WRITE page.
 *
 * Shell-agnostic, like the rest of this module: the `/me/notification-preferences`
 * API is owner-scoped to the auth user, so the page takes no agency/role input
 * and is mounted by BOTH shells via two route records (`/notifications/preferences`
 * agency, `/creator/notifications/preferences` creator) — reached from a
 * "Notification settings" item in the user menu.
 *
 * Honesty scope (D-2 / D-4 + the Ch3b review's role-filter): only the `in_app`
 * channel, only the live-emit types, AND only the types that actually target the
 * CURRENT user's recipient role are exposed — a toggle for a channel that gates
 * nothing, a notification that can't arrive yet, or one only the OTHER principal
 * receives would all be dead controls. The exposed list derives from the single
 * `LIVE_TYPES` registry in `../templates` (shared with the Ch3a renderer), so it
 * cannot drift. The backend stores SPARSELY (D-1: divergence → row,
 * return-to-default → delete) and ships the channel `defaults` over the wire, so
 * the display state is composed as `row?.is_enabled ?? defaults.in_app` and the
 * contract is never hardcoded here.
 */

import type {
  NotificationChannel,
  NotificationPreferencesEnvelope,
  NotificationType,
} from '@catalyst/api-client'
import { storeToRefs } from 'pinia'
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import { notificationsApi } from '../api/notifications.api'
import {
  preferenceGroupsForRole,
  recipientRoleForUserType,
  type NotificationPreferenceGroup,
} from '../templates'

const { t } = useI18n()
const { user } = storeToRefs(useAuthStore())

/**
 * The grouped, ROLE-FILTERED live types this user can set (D-4 + the review's
 * role filter). Derived from the shared `LIVE_TYPES` registry off the user's
 * `user_type` — a creator sees the creator-facing types, an agency user the
 * agency-facing ones; neither sees a toggle for a notification it can't receive.
 * Each type carries the CHANNELS it supports (Sprint 11, D-10): `in_app` for
 * all, plus `digest` for the messaging types whose daily-digest consumer ships.
 */
const preferenceGroups = computed(() =>
  preferenceGroupsForRole(recipientRoleForUserType(user.value?.attributes.user_type ?? 'creator')),
)

/** Every (type, channel) toggle this user can set — the flat save/compose set. */
const allRows = computed(() =>
  preferenceGroups.value.flatMap((group) =>
    group.types.flatMap((typeView) =>
      typeView.channels.map((channel) => ({ type: typeView.type, channel })),
    ),
  ),
)

/** The flat i18n label key for a prefs group. */
function groupLabelKey(group: NotificationPreferenceGroup): string {
  return `notifications.preferences.groups.${group}`
}

/** The flat i18n label key for a type (mirrors the dotted→underscore precedent). */
function typeLabelKey(type: string): string {
  return `notifications.preferences.typeLabels.${type.replace(/\./g, '_')}`
}

/** The i18n label key for a channel toggle. */
function channelLabelKey(channel: NotificationChannel): string {
  return `notifications.preferences.channels.${channel}`
}

/** The composed display-state key for a (type, channel) pair. */
function stateKey(type: NotificationType, channel: NotificationChannel): string {
  return `${type}::${channel}`
}

/** The composed display state: `${type}::${channel}` → whether it's on. */
const enabled = reactive<Record<string, boolean>>({})

const loading = ref(true)
const saving = ref(false)
const loadError = ref(false)
const saveError = ref(false)
const saveSuccess = ref(false)

/** Compose display state from the sparse rows + the defaults block (D-3). */
function applyState(envelope: NotificationPreferencesEnvelope): void {
  const { preferences, defaults } = envelope.data.attributes

  const overrides = new Map<string, boolean>()
  for (const row of preferences) {
    overrides.set(stateKey(row.notification_type, row.channel), row.is_enabled)
  }

  for (const { type, channel } of allRows.value) {
    const key = stateKey(type, channel)
    enabled[key] = overrides.get(key) ?? defaults[channel]
  }
}

async function loadPreferences(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    applyState(await notificationsApi.getPreferences())
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

async function onSave(): Promise<void> {
  saving.value = true
  saveError.value = false
  saveSuccess.value = false
  try {
    // Send the full visible set; the backend reconciles to sparse rows (D-1).
    const result = await notificationsApi.updatePreferences({
      preferences: allRows.value.map(({ type, channel }) => ({
        notification_type: type,
        channel,
        is_enabled: enabled[stateKey(type, channel)] ?? true,
      })),
    })
    applyState(result)
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 4000)
  } catch {
    saveError.value = true
  } finally {
    saving.value = false
  }
}

onMounted(loadPreferences)
</script>

<template>
  <v-card max-width="640" class="mx-auto pa-6" data-test="notification-preferences-page">
    <h1 class="text-h5 mb-1" data-test="prefs-heading">
      {{ t('notifications.preferences.title') }}
    </h1>
    <p class="text-body-2 text-medium-emphasis mb-6">
      {{ t('notifications.preferences.description') }}
    </p>

    <v-skeleton-loader v-if="loading" type="article" data-test="prefs-skeleton" />

    <v-alert v-else-if="loadError" type="error" variant="tonal" data-test="prefs-load-error">
      {{ t('notifications.preferences.loadError') }}
    </v-alert>

    <template v-else>
      <v-alert
        v-if="saveSuccess"
        type="success"
        variant="tonal"
        class="mb-4"
        closable
        data-test="prefs-success"
        @click:close="saveSuccess = false"
      >
        {{ t('notifications.preferences.success') }}
      </v-alert>

      <form novalidate data-test="prefs-form" @submit.prevent="onSave">
        <div v-for="group in preferenceGroups" :key="group.group" class="mb-6">
          <h2
            class="text-subtitle-1 font-weight-medium mb-2"
            :data-test="`prefs-group-${group.group}`"
          >
            {{ t(groupLabelKey(group.group)) }}
          </h2>

          <div
            v-for="typeView in group.types"
            :key="typeView.type"
            class="mb-3"
            :data-test="`prefs-type-${typeView.type}`"
          >
            <div class="text-body-2 mb-1">{{ t(typeLabelKey(typeView.type)) }}</div>

            <v-switch
              v-for="channel in typeView.channels"
              :key="channel"
              v-model="enabled[stateKey(typeView.type, channel)]"
              :label="t(channelLabelKey(channel))"
              color="primary"
              density="compact"
              hide-details
              class="ms-2"
              :data-test="`prefs-toggle-${typeView.type}-${channel}`"
            />
          </div>
        </div>

        <div
          v-if="saveError"
          role="alert"
          aria-live="polite"
          class="text-error text-body-2 mb-3"
          data-test="prefs-save-error"
        >
          {{ t('notifications.preferences.saveError') }}
        </div>

        <div class="d-flex justify-end">
          <v-btn
            type="submit"
            color="primary"
            :loading="saving"
            :disabled="saving"
            data-test="prefs-save-btn"
          >
            {{
              saving ? t('notifications.preferences.saving') : t('notifications.preferences.save')
            }}
          </v-btn>
        </div>
      </form>
    </template>
  </v-card>
</template>
