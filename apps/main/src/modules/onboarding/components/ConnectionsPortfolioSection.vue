<script setup lang="ts">
/**
 * ConnectionsPortfolioSection — the Portfolio sub-section of the merged
 * "Connections" wizard step (ad-hoc AH-003 D2). This is the former
 * Step4PortfolioPage body, extracted verbatim so the upload-queue +
 * gallery + remove logic is unchanged; the page-level header and the
 * single "Continue" affordance now live on the parent
 * {@link Step3ConnectionsPage}.
 */

import type { CreatorPortfolioItemSummary } from '@catalyst/api-client'
import { PortfolioGallery } from '@catalyst/ui'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import PortfolioUploadGrid from './PortfolioUploadGrid.vue'
import { resolveSubmitErrorKey } from '../composables/useSubmitErrorKey'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const store = useOnboardingStore()

const removeErrorKey = ref<string | null>(null)

const galleryItems = computed(() => {
  const items: ReadonlyArray<CreatorPortfolioItemSummary> =
    store.creator?.attributes.portfolio ?? []
  return items.map((item) => ({
    id: item.id,
    kind: item.kind,
    title: item.title,
    description: item.description,
    thumbnailUrl: item.thumbnail_view_url ?? (item.kind === 'image' ? item.view_url : null),
    viewUrl: item.view_url,
    externalUrl: item.external_url,
    altText: item.title ?? t('creator.ui.wizard.steps.portfolio.untitled_item'),
  }))
})

const announceLine = computed(() =>
  t('creator.ui.wizard.steps.portfolio.gallery_status', {
    count: galleryItems.value.length,
  }),
)

async function onRemove(itemId: string): Promise<void> {
  removeErrorKey.value = null
  try {
    await store.removePortfolioItem(itemId)
  } catch (error) {
    removeErrorKey.value = resolveSubmitErrorKey(error, 'creator.ui.errors.upload_failed')
  }
}
</script>

<template>
  <section class="portfolio-step" data-testid="step-portfolio">
    <header class="portfolio-step__header">
      <h3 class="text-subtitle-1">{{ t('creator.ui.wizard.steps.portfolio.title') }}</h3>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.portfolio.description') }}
      </p>
    </header>

    <div class="portfolio-step__upload">
      <PortfolioUploadGrid />
    </div>

    <div class="portfolio-step__gallery" data-testid="portfolio-step-gallery">
      <h4 class="text-subtitle-2">
        {{ t('creator.ui.wizard.steps.portfolio.gallery_heading') }}
      </h4>
      <PortfolioGallery
        :items="galleryItems"
        :editable="true"
        :empty-label="t('creator.ui.wizard.steps.portfolio.gallery_empty')"
        :remove-label="t('creator.ui.wizard.steps.portfolio.gallery_remove')"
        :video-label="t('creator.ui.wizard.steps.portfolio.video_badge_label')"
        :link-label="t('creator.ui.wizard.steps.portfolio.link_badge_label')"
        :preview-label="t('creator.ui.wizard.steps.portfolio.preview_label')"
        :close-label="t('creator.ui.wizard.steps.portfolio.preview_close')"
        @remove="onRemove"
      />
      <div
        v-if="removeErrorKey"
        role="alert"
        class="portfolio-step__remove-error"
        data-testid="portfolio-step-remove-error"
      >
        {{ t(removeErrorKey) }}
      </div>
    </div>

    <div class="portfolio-step__sr-status" role="status" aria-live="polite" aria-atomic="true">
      {{ announceLine }}
    </div>
  </section>
</template>

<style scoped>
.portfolio-step {
  display: flex;
  flex-direction: column;
  gap: 24px;
  max-width: 840px;
}

.portfolio-step__gallery {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.portfolio-step__remove-error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.portfolio-step__sr-status {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
