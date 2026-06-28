<script setup lang="ts">
import PortfolioGallery from './PortfolioGallery.vue'

/**
 * PortfolioDrawer — a wide `v-dialog` "drawer" (the ReviewDraftDrawer
 * precedent; this app has no `v-navigation-drawer`) that opens to list ALL of
 * a creator's portfolio assets in the shared {@link PortfolioGallery}, with
 * per-item download (AH-004 D10).
 *
 * Reused verbatim across the three viewing surfaces — creator-owner,
 * agency-user (roster/discover), platform-admin — so the gallery + download
 * affordance are identical everywhere. Like every `@catalyst/ui` component it
 * is transport-free and i18n-free: the caller passes pre-mapped items and
 * localized label strings.
 *
 * Authorization is the CALLER's: the drawer only ever receives items the
 * surface was already authorized to render, and the `download_url`s embedded
 * in those items were minted server-side behind the same per-surface gate as
 * `view_url` — so opening the drawer / downloading is never a broader grant
 * than viewing the page that hosts it.
 */

interface PortfolioDrawerItem {
  id: string
  kind: 'image' | 'video' | 'link'
  title: string | null
  description: string | null
  thumbnailUrl: string | null
  viewUrl?: string | null
  externalUrl: string | null
  altText: string
  processingStatus?: 'processing' | 'ready' | 'failed'
  downloadUrl?: string | null
}

interface Props {
  modelValue: boolean
  items: ReadonlyArray<PortfolioDrawerItem>
  /** Drawer heading. */
  title?: string
  /** When true, the embedded gallery shows per-item delete affordances. */
  editable?: boolean
  /** Localized labels forwarded to the embedded gallery. */
  emptyLabel?: string
  removeLabel?: string
  videoLabel?: string
  linkLabel?: string
  previewLabel?: string
  closeLabel?: string
  processingLabel?: string
  failedLabel?: string
  downloadLabel?: string
  /** Localized accessible label for the copy-link affordance. */
  copyLinkLabel?: string
}

const props = withDefaults(defineProps<Props>(), {
  title: 'Portfolio',
  editable: false,
  emptyLabel: '—',
  removeLabel: 'Remove item',
  videoLabel: 'Video',
  linkLabel: 'External link',
  previewLabel: 'Open preview',
  closeLabel: 'Close',
  processingLabel: 'Processing…',
  failedLabel: 'Upload failed',
  downloadLabel: 'Download',
  copyLinkLabel: 'Copy link',
})

const emit = defineEmits<{
  (event: 'update:modelValue', value: boolean): void
  (event: 'remove', itemId: string): void
}>()

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="props.modelValue"
    max-width="900"
    scrollable
    data-testid="portfolio-drawer"
    @update:model-value="(value: boolean) => emit('update:modelValue', value)"
  >
    <v-card>
      <v-card-title class="d-flex align-center justify-space-between">
        <span>{{ props.title }}</span>
        <button
          type="button"
          class="portfolio-drawer__close"
          :aria-label="props.closeLabel"
          data-testid="portfolio-drawer-close"
          @click="close"
        >
          <v-icon icon="mdi-close" size="22" aria-hidden="true" />
        </button>
      </v-card-title>
      <v-divider />
      <v-card-text>
        <PortfolioGallery
          :items="props.items"
          :editable="props.editable"
          :empty-label="props.emptyLabel"
          :remove-label="props.removeLabel"
          :video-label="props.videoLabel"
          :link-label="props.linkLabel"
          :preview-label="props.previewLabel"
          :close-label="props.closeLabel"
          :processing-label="props.processingLabel"
          :failed-label="props.failedLabel"
          :download-label="props.downloadLabel"
          :copy-link-label="props.copyLinkLabel"
          @remove="(itemId: string) => emit('remove', itemId)"
        />
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.portfolio-drawer__close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 1px solid rgb(var(--v-theme-outline-variant));
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}

.portfolio-drawer__close:hover,
.portfolio-drawer__close:focus-visible {
  background: rgb(var(--v-theme-surface-variant));
  outline: none;
}
</style>
