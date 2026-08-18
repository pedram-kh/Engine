<script setup lang="ts">
/**
 * AH-010b — the CREATOR full-screen relationship thread. Keyed by the agency
 * ULID (route param). The counterparty header is resolved from the `?name=`
 * navigation hint first (instant, no flash) then refined from the inbox lookup
 * (authoritative on a hard refresh/deep-link). The transport is bound to the
 * agency; the thread view drives the rest.
 *
 * The AH-013 two-pane shell keeps this component MOUNTED across conversation
 * switches — only the route param changes — so the header must re-resolve on
 * every param change, not once on mount.
 */

import type { CreatorRelationshipThreadRow } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
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

// Clearing FIRST matters: until the new row lands, the header falls back to the
// `?name=` hint (correct name, initials) instead of showing the previous
// agency's name and logo over someone else's conversation.
watch(
  agencyUlid,
  async (ulid) => {
    resolvedRow.value = null
    if (ulid === '') {
      return
    }
    try {
      const res = await relationshipMessagingApi.creatorInbox()
      // Clicking through conversations quickly can land these out of order —
      // only the response for the conversation still on screen may write it.
      if (agencyUlid.value !== ulid) {
        return
      }
      resolvedRow.value = res.data.find((row) => row.attributes.agency.id === ulid) ?? null
    } catch {
      // The name hint / fallback covers the header; not load-bearing.
    }
  },
  { immediate: true },
)
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
