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
import { useRoute } from 'vue-router'

import {
  creatorRelationshipTransport,
  relationshipMessagingApi,
} from '../api/relationshipMessaging.api'
import RelationshipThreadView from '../components/RelationshipThreadView.vue'

const { t } = useI18n()
const route = useRoute()

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

const avatarUrl = computed(() => {
  const path = resolvedRow.value?.attributes.agency.logo_path ?? null
  return path !== null && /^https?:\/\//i.test(path) ? path : null
})

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
    <v-btn
      variant="text"
      size="small"
      prepend-icon="mdi-arrow-left"
      class="mb-2"
      :to="{ name: 'creator.messages' }"
      data-test="relationship-thread-back"
    >
      {{ t('app.messaging.relationship.back') }}
    </v-btn>

    <RelationshipThreadView :transport="transport" :title="title" :avatar-url="avatarUrl" />
  </section>
</template>
