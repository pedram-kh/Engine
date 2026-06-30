<script setup lang="ts">
/**
 * AH-010b — the AGENCY full-screen relationship thread. Keyed by the creator
 * ULID (route param); the agency is the current workspace. Reached from the
 * inbox OR the roster-detail "Message" shortcut (which may open a not-yet-
 * provisioned thread — provisioning is lazy on first send). The counterparty
 * header uses the `?name=` hint, refined from the inbox lookup when the thread
 * already exists.
 */

import type { AgencyRelationshipThreadRow } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
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

onMounted(async () => {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) {
    return
  }
  try {
    const res = await relationshipMessagingApi.agencyInbox(agencyId)
    resolvedRow.value =
      res.data.find((row) => row.attributes.creator.id === creatorUlid.value) ?? null
  } catch {
    // The name hint / fallback covers the header; not load-bearing.
  }
})
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
