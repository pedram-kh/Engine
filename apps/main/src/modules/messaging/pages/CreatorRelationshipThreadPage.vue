<script setup lang="ts">
/**
 * AH-010b — the CREATOR full-screen relationship thread. Keyed by the agency
 * ULID (route param). The counterparty header is resolved from the `?name=`
 * navigation hint first (instant, no flash) then refined from the inbox lookup
 * on mount (authoritative on a hard refresh/deep-link). The transport is bound
 * to the agency; the thread view drives the rest.
 */

import type { CreatorRelationshipThreadRow } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { type RouteLocationRaw, useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'

import {
  creatorRelationshipTransport,
  relationshipMessagingApi,
} from '../api/relationshipMessaging.api'
import RelationshipThreadView from '../components/RelationshipThreadView.vue'

const { t } = useI18n()
const route = useRoute()
const display = useDisplay()

// AH-013 — desktop renders this in the two-pane right column; the header back
// chevron is mobile-only (single-pane). Null on desktop hides it in the view.
const backTo = computed<RouteLocationRaw | null>(() =>
  display.smAndDown.value ? { name: 'creator.messages' } : null,
)

const agencyUlid = computed(() => String(route.params.agencyUlid ?? ''))
const nameHint = computed(() => (typeof route.query.name === 'string' ? route.query.name : ''))

const resolvedRow = ref<CreatorRelationshipThreadRow | null>(null)

const transport = computed(() =>
  agencyUlid.value === '' ? null : creatorRelationshipTransport(agencyUlid.value),
)

const title = computed(
  () =>
    resolvedRow.value?.attributes.agency.name ??
    (nameHint.value || t('app.messaging.relationship.inboxTitle')),
)

// AH-013 — the resolved agency logo for the thread header (null → initials).
const avatarUrl = computed(() => resolvedRow.value?.attributes.agency.logo_url ?? null)

onMounted(async () => {
  try {
    const res = await relationshipMessagingApi.creatorInbox()
    resolvedRow.value =
      res.data.find((row) => row.attributes.agency.id === agencyUlid.value) ?? null
  } catch {
    // The name hint / fallback covers the header; not load-bearing.
  }
})
</script>

<template>
  <section data-test="creator-thread-page">
    <RelationshipThreadView
      :transport="transport"
      :title="title"
      :avatar-url="avatarUrl"
      :back-to="backTo"
    />
  </section>
</template>
