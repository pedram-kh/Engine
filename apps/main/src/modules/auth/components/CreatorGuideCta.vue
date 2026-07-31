<script setup lang="ts">
/**
 * Creator-guide call-to-action for the rebrand sign-in landing (Figma
 * "Rebrand" node 359-1253 desktop / 359-1663 mobile): a fanned trio of
 * guide photographs beside the pitch copy and a download pill.
 *
 * Rendered by AuthLayout in hero mode only. All copy comes from the
 * `auth.ui.guide.*` i18n bundle; the photographs are decorative, so
 * they carry an empty alt rather than a translated description.
 *
 * The pill is an `<a>`, not a `v-btn` — it navigates to a static asset
 * rather than invoking an action, and `target="_blank"` hands the PDF to
 * the browser's own viewer so the sign-in page is never navigated away
 * from. The guide is served from `apps/main/public/`, so Vite exposes it
 * at the web root with no import and no bundler involvement.
 */

import { useI18n } from 'vue-i18n'

import guideCard1 from '@/modules/auth/assets/guide/guide-card-1.webp'
import guideCard2 from '@/modules/auth/assets/guide/guide-card-2.webp'
import guideCard3 from '@/modules/auth/assets/guide/guide-card-3.webp'

/** Web-root path of the committed guide PDF (apps/main/public/). */
const GUIDE_PDF_HREF = '/creator-guide.pdf'

const { t } = useI18n()
</script>

<template>
  <section class="guide-cta" data-test="auth-guide-cta">
    <div class="guide-cta__fan">
      <img :src="guideCard1" alt="" class="guide-cta__card guide-cta__card--back" loading="lazy" />
      <img :src="guideCard2" alt="" class="guide-cta__card guide-cta__card--mid" loading="lazy" />
      <img :src="guideCard3" alt="" class="guide-cta__card guide-cta__card--front" loading="lazy" />
    </div>

    <div class="guide-cta__copy">
      <p class="guide-cta__heading">{{ t('auth.ui.guide.heading') }}</p>
      <p class="guide-cta__body">{{ t('auth.ui.guide.body') }}</p>
      <a
        :href="GUIDE_PDF_HREF"
        target="_blank"
        rel="noopener noreferrer"
        class="guide-cta__button"
        data-test="auth-guide-download"
      >
        {{ t('auth.ui.guide.cta') }}
      </a>
    </div>
  </section>
</template>

<style scoped>
.guide-cta {
  display: flex;
  align-items: center;
  gap: var(--space-12);
}

/* Fan geometry is expressed in percentages of this box so the trio
 * scales as one unit. Ratio + per-card offsets are read straight off the
 * Figma group (706.786 x 742.751, cards 329/305/290 wide). */
.guide-cta__fan {
  position: relative;
  flex: 0 0 auto;
  width: clamp(280px, 36.8vw, 707px);
  aspect-ratio: 706.786 / 742.751;
}

.guide-cta__card {
  position: absolute;
  aspect-ratio: 660 / 939;
  border: 1px solid var(--auth-hairline);
  border-radius: var(--radius-sm);
  object-fit: cover;
}

/* Left card: furthest back, greatest tilt. */
.guide-cta__card--back {
  left: 33.23%;
  top: 46.7%;
  width: 46.6%;
  transform: translate(-50%, -50%) rotate(-20deg);
}

.guide-cta__card--mid {
  left: 46.33%;
  top: 50.06%;
  width: 43.1%;
  transform: translate(-50%, -50%) rotate(-10deg);
}

/* Right card: upright and on top, so it reads as the cover. */
.guide-cta__card--front {
  left: 60.73%;
  top: 54.08%;
  width: 41.03%;
  transform: translate(-50%, -50%);
}

.guide-cta__copy {
  flex: 1 1 auto;
}

.guide-cta__heading {
  margin: 0 0 var(--space-6);
  font-family: var(--auth-display-font);
  /* 40px on the 1920px Figma frame, fluid below it. */
  font-size: clamp(24px, 2.08vw, 40px);
  font-weight: 400;
  line-height: 1.3;
  color: var(--auth-page-fg);
}

.guide-cta__body {
  margin: 0 0 var(--space-10);
  max-width: 716px;
  font-size: clamp(16px, 1.25vw, 24px);
  line-height: 1.3;
  color: var(--auth-page-fg-muted);
}

.guide-cta__button {
  position: relative;
  display: inline-block;
  padding: var(--space-6) var(--space-10);
  border-radius: var(--radius-full);
  /* The landing is a fixed dark surface, so its foreground/background
   * tokens are exactly the pill's inverted pair. */
  background: var(--auth-page-fg);
  color: var(--auth-page-bg);
  font-family: var(--auth-display-font);
  font-size: clamp(16px, 1.04vw, 20px);
  font-weight: 500;
  line-height: 1.7;
  text-align: center;
  text-decoration: none;
  text-transform: uppercase;
}

/* Aurora bloom behind the pill (Decision D7 thin-accent consumption:
 * the utility var, never a raw hex). Sits under the label via a
 * negative z-index on a positioned parent. */
.guide-cta__button::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: -1;
  border-radius: inherit;
  background: var(--brand-aurora-gradient);
  opacity: 0.3;
  filter: blur(35px);
  pointer-events: none;
}

/* Mobile: one column, and the heading rises above the fan. `contents`
 * unwraps the copy block so its children become flex items of the
 * section alongside the fan — the same technique AuthHeroPanel uses to
 * reorder its footnote around the sign-in card. */
@media (max-width: 1100px) {
  .guide-cta {
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-6);
  }

  .guide-cta__copy {
    display: contents;
  }

  .guide-cta__fan {
    order: 1;
    width: 100%;
    align-self: center;
  }

  .guide-cta__heading {
    order: 0;
    margin-bottom: 0;
  }

  .guide-cta__body {
    order: 2;
    margin-bottom: 0;
  }

  .guide-cta__button {
    order: 3;
  }
}
</style>
