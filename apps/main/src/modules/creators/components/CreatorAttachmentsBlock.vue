<script setup lang="ts">
/**
 * CreatorAttachmentsBlock — the shared attachment list for the two creator
 * surfaces that read `attributes.attachments` (AH-092, D4/S6): the
 * assignment-detail page and the Jobs Board job-detail page. Both surfaces
 * consume the identical `CreatorCampaignAttachment[]` shape (filename, mime,
 * size, signed `url`) produced by the same `creatorVisible()`-scoped read
 * path, so one component renders both — a divergence here would be a UI-side
 * re-introduction of the very inconsistency D4 was written to prevent.
 *
 * Renders nothing when the array is empty (not-visible or never-attached is
 * the overwhelmingly common case on both surfaces) — there is no empty state
 * to show a creator for a section that, from their side, never existed.
 */
import { formatFileSize, type CreatorCampaignAttachment } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

defineProps<{
  attachments: CreatorCampaignAttachment[]
}>()

const { t } = useI18n()
</script>

<template>
  <section
    v-if="attachments.length > 0"
    class="creator-attachments-block"
    data-testid="creator-attachments-block"
  >
    <h2 class="text-subtitle-1">{{ t('creator.ui.attachments.title') }}</h2>
    <v-list density="compact" class="creator-attachments-block__list">
      <v-list-item
        v-for="(attachment, index) in attachments"
        :key="`${attachment.filename}-${index}`"
        :href="attachment.url ?? undefined"
        target="_blank"
        rel="noopener noreferrer"
        :data-testid="`creator-attachment-${index}`"
      >
        <template #prepend>
          <v-icon icon="mdi-paperclip" size="small" />
        </template>
        <v-list-item-title>{{ attachment.filename }}</v-list-item-title>
        <v-list-item-subtitle>{{ formatFileSize(attachment.size_bytes) }}</v-list-item-subtitle>
        <template v-if="attachment.url" #append>
          <v-icon icon="mdi-download" size="small" />
        </template>
      </v-list-item>
    </v-list>
  </section>
</template>

<style scoped>
.creator-attachments-block {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.creator-attachments-block__list {
  background: transparent;
}
</style>
