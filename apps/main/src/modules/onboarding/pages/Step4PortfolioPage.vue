<script setup lang="ts">
/**
 * Step4PortfolioPage — wizard Step 4 (Portfolio).
 *
 * Sprint 3 Chunk 3 sub-step 6.
 *
 * Layout (top-to-bottom):
 *   1. `PortfolioUploadGrid` — the (in-flight) upload queue,
 *      bounded-3 concurrency, image direct-multipart, video
 *      presigned PUT flow. Owned by `useAvatarUpload` peer:
 *      `usePortfolioUpload` (sub-step 3).
 *   2. `PortfolioGallery` — the persisted items, sourced from
 *      `creator.attributes.portfolio` on bootstrap state.
 *      Editable: tapping the remove affordance calls
 *      `useOnboardingStore.removePortfolioItem(id)` which
 *      delegates to `DELETE /api/v1/creators/me/portfolio/{ulid}`
 *      and re-bootstraps.
 *   3. Continue button — enabled once at least one persisted item
 *      exists (Spec § 6.1 Step 4: "min 1 piece to advance").
 *
 * Decisions applied:
 *   - Decision C1: form-main lives here; the gallery component
 *     ships in `@catalyst/ui` so the admin creator-detail page
 *     (sub-step 9) can reuse the read-only render.
 *   - Decision F1=a (bounded portfolio upload concurrency = 3)
 *     is enforced inside `usePortfolioUpload` — this page only
 *     binds the UI.
 *
 * a11y (F2=b): the "advance" button uses `:disabled` + a live
 * status region announces upload queue + gallery membership
 * counts so screen-reader users get the same feedback as sighted
 * users.
 */

import type { CreatorPortfolioItemSummary } from '@catalyst/api-client'
import { PortfolioGallery } from '@catalyst/ui'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import PortfolioUploadGrid from '../components/PortfolioUploadGrid.vue'
import { resolveSubmitErrorKey } from '../composables/useSubmitErrorKey'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
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
    // Backend mints presigned GET URLs against the private `media`
    // disk on every bootstrap; prefer the dedicated thumbnail URL.
    // For images we fall back to the full-size view URL when no
    // thumbnail exists. For videos `view_url` points at the raw media
    // (e.g. an .mp4) which is NOT a valid `<img src>` — feeding it to
    // the gallery yields a broken-image tile, so we leave it null and
    // let the gallery render its placeholder + play badge instead.
    // The raw `*_path` fields are storage keys and are NOT directly
    // browser-fetchable.
    thumbnailUrl: item.thumbnail_view_url ?? (item.kind === 'image' ? item.view_url : null),
    // Full-size signed media for the click-to-preview lightbox: the full
    // image for `image`, the playable file for `video`.
    viewUrl: item.view_url,
    externalUrl: item.external_url,
    altText: item.title ?? t('creator.ui.wizard.steps.portfolio.untitled_item'),
  }))
})

const canAdvance = computed(() => galleryItems.value.length > 0)

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

async function advance(): Promise<void> {
  if (!canAdvance.value) return
  await router.push({ name: 'onboarding.kyc' })
}
</script>

<template>
  <section class="portfolio-step" data-testid="step-portfolio">
    <header class="portfolio-step__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.portfolio.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.portfolio.description') }}
      </p>
    </header>

    <div class="portfolio-step__upload">
      <PortfolioUploadGrid />
    </div>

    <div class="portfolio-step__gallery" data-testid="portfolio-step-gallery">
      <h3 class="text-subtitle-1">
        {{ t('creator.ui.wizard.steps.portfolio.gallery_heading') }}
      </h3>
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

    <div class="portfolio-step__actions">
      <v-btn
        color="primary"
        :disabled="!canAdvance"
        :loading="store.isLoadingPortfolio"
        data-testid="portfolio-advance"
        @click="advance"
      >
        {{ t('creator.ui.wizard.actions.save_and_continue') }}
      </v-btn>
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

.portfolio-step__actions {
  display: flex;
  justify-content: flex-end;
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
