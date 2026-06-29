<script setup lang="ts">
/**
 * AH-012 — the WhatsApp-style "new conversation" contact picker. A shared,
 * presentation-only dialog used by BOTH surfaces: the parent fetches its own
 * gate-filtered contacts (agency → messageable-creators, creator →
 * messageable-agencies), normalizes them to {@link ContactPickerItem}, and the
 * dialog renders the list with the same row shape as {@link RelationshipInbox}.
 *
 * Picking a contact navigates to the thread route (`to`) — which may be a
 * not-yet-provisioned thread; the row materializes on the first send (D1). The
 * UNIQUE pair guarantees picking a contact who already has a thread routes into
 * that same thread (no parallel thread — Q4).
 *
 * Search + pagination (the agency side, D6) are driven by the parent: the picker
 * emits `update:search` + `loadMore`, the parent owns debouncing + accumulation.
 */

import type { RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'

export interface ContactPickerItem {
  /** Stable key + counterparty ULID. */
  id: string
  title: string
  avatarText: string
  avatarUrl?: string | null
  to: RouteLocationRaw
}

withDefaults(
  defineProps<{
    modelValue: boolean
    title: string
    items: ContactPickerItem[]
    loading: boolean
    loadError: boolean
    emptyLabel: string
    searchable?: boolean
    search?: string
    searchPlaceholder?: string
    hasMore?: boolean
    loadingMore?: boolean
  }>(),
  {
    searchable: false,
    search: '',
    searchPlaceholder: '',
    hasMore: false,
    loadingMore: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'update:search': [value: string]
  loadMore: []
}>()

const { t } = useI18n()

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="480"
    scrollable
    data-test="contact-picker"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <v-card>
      <v-card-title class="d-flex align-center justify-space-between ga-2">
        <span>{{ title }}</span>
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          :aria-label="t('app.messaging.relationship.picker.close')"
          data-test="contact-picker-close"
          @click="close"
        />
      </v-card-title>

      <v-text-field
        v-if="searchable"
        :model-value="search"
        :placeholder="searchPlaceholder"
        prepend-inner-icon="mdi-magnify"
        density="compact"
        variant="outlined"
        hide-details
        clearable
        class="mx-4 mb-2"
        data-test="contact-picker-search"
        @update:model-value="emit('update:search', $event ?? '')"
      />

      <v-divider />

      <v-card-text class="pa-0">
        <v-skeleton-loader v-if="loading" type="list-item-avatar-two-line@4" />

        <v-alert
          v-else-if="loadError"
          type="error"
          variant="tonal"
          density="compact"
          class="ma-4"
          data-test="contact-picker-error"
        >
          {{ t('app.messaging.relationship.picker.loadError') }}
        </v-alert>

        <p
          v-else-if="items.length === 0"
          class="contact-picker__empty"
          data-test="contact-picker-empty"
        >
          {{ emptyLabel }}
        </p>

        <v-list v-else data-test="contact-picker-list">
          <v-list-item
            v-for="item in items"
            :key="item.id"
            :to="item.to"
            :data-test="`contact-picker-row-${item.id}`"
            @click="close"
          >
            <template #prepend>
              <v-avatar size="40" color="primary">
                <v-img v-if="item.avatarUrl" :src="item.avatarUrl" :alt="item.title" />
                <span v-else class="text-body-2 font-weight-bold text-white">
                  {{ (item.avatarText || '?')[0]?.toUpperCase() }}
                </span>
              </v-avatar>
            </template>

            <v-list-item-title>{{ item.title }}</v-list-item-title>
          </v-list-item>

          <div v-if="hasMore" class="contact-picker__more">
            <v-btn
              variant="text"
              size="small"
              :loading="loadingMore"
              data-test="contact-picker-load-more"
              @click="emit('loadMore')"
            >
              {{ t('app.messaging.relationship.picker.loadMore') }}
            </v-btn>
          </div>
        </v-list>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.contact-picker__empty {
  opacity: 0.6;
  text-align: center;
  padding: 32px 0;
}

.contact-picker__more {
  text-align: center;
  padding: 8px 0;
}
</style>
