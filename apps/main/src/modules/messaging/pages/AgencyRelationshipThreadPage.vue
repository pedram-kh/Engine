<script setup lang="ts">
/**
 * AH-010b — the AGENCY full-screen relationship thread. Keyed by the creator
 * ULID (route param); the agency is the current workspace. Reached from the
 * inbox OR the roster-detail "Message" shortcut (which may open a not-yet-
 * provisioned thread — provisioning is lazy on first send). The counterparty
 * header uses the `?name=` hint, refined from the inbox lookup when the thread
 * already exists.
 *
 * The AH-013 two-pane shell keeps this component MOUNTED across conversation
 * switches — only the route param changes — so the header must re-resolve on
 * every param change, not once on mount.
 */

import type { AgencyRelationshipThreadRow } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { type RouteLocationRaw, useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'

import { useAgencyStore } from '@/core/stores/useAgencyStore'

import {
  agencyRelationshipTransport,
  relationshipMessagingApi,
} from '../api/relationshipMessaging.api'
import RelationshipThreadView from '../components/RelationshipThreadView.vue'

const { t } = useI18n()
const route = useRoute()
const agencyStore = useAgencyStore()
const display = useDisplay()

// AH-013 — the thread renders in the two-pane right column on desktop, so the
// header back chevron is only needed on mobile (single-pane). Null hides it.
const backTo = computed<RouteLocationRaw | null>(() =>
  display.smAndDown.value ? { name: 'messages.inbox' } : null,
)

const creatorUlid = computed(() => String(route.params.creatorUlid ?? ''))
const nameHint = computed(() => (typeof route.query.name === 'string' ? route.query.name : ''))

const resolvedRow = ref<AgencyRelationshipThreadRow | null>(null)

const transport = computed(() => {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || creatorUlid.value === '') {
    return null
  }
  return agencyRelationshipTransport(agencyId, creatorUlid.value)
})

const title = computed(
  () =>
    resolvedRow.value?.attributes.creator.display_name ??
    (nameHint.value || t('app.messaging.relationship.inboxTitle')),
)

// AH-013 — the signed creator avatar for the thread header (null → initials).
const avatarUrl = computed(() => resolvedRow.value?.attributes.creator.avatar_url ?? null)

// Clearing FIRST matters: until the new row lands, the header falls back to the
// `?name=` hint (correct name, initials) instead of showing the previous
// creator's name and photo over someone else's conversation.
watch(
  [creatorUlid, () => agencyStore.currentAgencyId],
  async ([ulid, agencyId]) => {
    resolvedRow.value = null
    if (agencyId === null || ulid === '') {
      return
    }
    try {
      const res = await relationshipMessagingApi.agencyInbox(agencyId)
      // Clicking through conversations quickly can land these out of order —
      // only the response for the conversation still on screen may write it.
      if (creatorUlid.value !== ulid || agencyStore.currentAgencyId !== agencyId) {
        return
      }
      resolvedRow.value = res.data.find((row) => row.attributes.creator.id === ulid) ?? null
    } catch {
      // The name hint / fallback covers the header; not load-bearing.
    }
  },
  { immediate: true },
)
</script>

<template>
  <section data-test="agency-thread-page">
    <RelationshipThreadView
      :transport="transport"
      :title="title"
      :avatar-text="title"
      :avatar-url="avatarUrl"
      :back-to="backTo"
    />
  </section>
</template>
