<script setup lang="ts">
/**
 * PortfolioGallery — render a creator's persisted portfolio items
 * as a responsive grid of thumbnails.
 *
 * Sprint 3 Chunk 3 sub-step 6 (Decision C1: display-shared,
 * form-main). The consumer (creator wizard Step 4, admin
 * creator-detail page) passes pre-resolved view URLs — this
 * package does NOT call the API, does NOT compute storage paths,
 * and does NOT make i18n decisions. The string labels (alt text,
 * empty-state copy, button labels) flow in via props so the
 * shared package stays both i18n-free and transport-free.
 *
 * Items are keyed by `id` (ulid). Each item carries a `kind`
 * discriminator: `image` and `video` render their `thumbnailUrl`;
 * `link` renders a placeholder card with the title + external
 * URL. Videos overlay a play-icon affordance.
 *
 * a11y (F2=b): the grid is a `<ul>` with each item a `<li>`, the
 * thumbnail `<img>` carries the `alt` text composed by the
 * caller (item title or fallback), and the delete affordance is
 * a button (NOT an anchor) with an accessible name.
 */

interface PortfolioGalleryItem {
  id: string
  kind: 'image' | 'video' | 'link'
  title: string | null
  description: string | null
  thumbnailUrl: string | null
  externalUrl: string | null
  altText: string
}

interface Props {
  items: ReadonlyArray<PortfolioGalleryItem>
  /** When true, the gallery shows a per-item delete affordance. */
  editable?: boolean
  /** Localized "no portfolio items yet" copy. */
  emptyLabel?: string
  /** Localized accessible label for the delete affordance. */
  removeLabel?: string
  /** Localized accessible label for the play-icon overlay on videos. */
  videoLabel?: string
  /** Localized accessible label for the external-link overlay on links. */
  linkLabel?: string
}

const props = withDefaults(defineProps<Props>(), {
  editable: false,
  emptyLabel: '—',
  removeLabel: 'Remove item',
  videoLabel: 'Video',
  linkLabel: 'External link',
})

const emit = defineEmits<{
  (event: 'remove', itemId: string): void
}>()

function onRemove(itemId: string): void {
  emit('remove', itemId)
}
</script>

<template>
  <ul v-if="props.items.length > 0" class="portfolio-gallery" data-testid="portfolio-gallery">
    <li
      v-for="item in props.items"
      :key="item.id"
      class="portfolio-gallery__item"
      :data-testid="`portfolio-gallery-item-${item.id}`"
    >
      <div class="portfolio-gallery__thumb-wrap">
        <img
          v-if="item.thumbnailUrl"
          class="portfolio-gallery__thumb"
          :src="item.thumbnailUrl"
          :alt="item.altText"
          loading="lazy"
        />
        <div v-else class="portfolio-gallery__placeholder" role="img" :aria-label="item.altText">
          <v-icon
            :icon="item.kind === 'link' ? 'mdi-link-variant' : 'mdi-image-outline'"
            size="32"
            aria-hidden="true"
          />
        </div>

        <span
          v-if="item.kind === 'video'"
          class="portfolio-gallery__badge"
          :aria-label="props.videoLabel"
        >
          <v-icon icon="mdi-play-circle" size="32" aria-hidden="true" />
        </span>
        <span
          v-else-if="item.kind === 'link'"
          class="portfolio-gallery__badge"
          :aria-label="props.linkLabel"
        >
          <v-icon icon="mdi-open-in-new" size="24" aria-hidden="true" />
        </span>
      </div>

      <div v-if="item.title" class="portfolio-gallery__title">
        {{ item.title }}
      </div>

      <button
        v-if="props.editable"
        type="button"
        class="portfolio-gallery__remove"
        :aria-label="`${props.removeLabel}${item.title ? ': ' + item.title : ''}`"
        :data-testid="`portfolio-gallery-remove-${item.id}`"
        @click="onRemove(item.id)"
      >
        <v-icon icon="mdi-close" size="18" aria-hidden="true" />
      </button>
    </li>
  </ul>
  <span v-else class="portfolio-gallery--empty" data-testid="portfolio-gallery-empty">
    {{ props.emptyLabel }}
  </span>
</template>

<style scoped>
.portfolio-gallery {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
}

.portfolio-gallery__item {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.portfolio-gallery__thumb-wrap {
  position: relative;
  aspect-ratio: 4 / 3;
  background: rgb(var(--v-theme-surface-variant));
  border-radius: 8px;
  overflow: hidden;
}

.portfolio-gallery__thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.portfolio-gallery__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgb(var(--v-theme-on-surface-variant));
}

.portfolio-gallery__badge {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-surface), 0.35);
}

.portfolio-gallery__title {
  font-size: 0.875rem;
  font-weight: 500;
  line-height: 1.3;
  word-break: break-word;
}

.portfolio-gallery__remove {
  position: absolute;
  top: 6px;
  right: 6px;
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1px solid rgb(var(--v-theme-outline-variant));
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}

.portfolio-gallery__remove:hover,
.portfolio-gallery__remove:focus-visible {
  background: rgb(var(--v-theme-error-container));
  color: rgb(var(--v-theme-on-error-container));
  outline: none;
}

.portfolio-gallery--empty {
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
