<script setup lang="ts">
/**
 * Marketing footer for the rebrand sign-in landing (Figma "Rebrand"
 * node 490-2 desktop / 490-44 mobile): the Catalyst monogram watermark
 * over a second aurora band, then navigation, social, contact, and the
 * registration/copyright row.
 *
 * Rendered by AuthLayout in hero mode only. Nav labels and "Privacy
 * policy" come from the `auth.ui.footer.*` i18n bundle; fixed business
 * data and the link destinations live in `footerLinks.ts`, which also
 * owns the inert-vs-anchor decision (see `footerLinkTag`). Every nav and
 * social destination is still outstanding, so those entries currently
 * render as plain text rather than dead anchors.
 *
 * The monogram is a self-contained SVG carrying its own aurora edge
 * gradients, so it needs no CSS colouring — it is decorative and hidden
 * from assistive tech.
 */

import { useI18n } from 'vue-i18n'

import catalystLogo from '@/modules/auth/assets/catalyst-logo.svg'
import catalystMonogram from '@/modules/auth/assets/catalyst-monogram.svg'
import {
  FOOTER_CONTACT,
  FOOTER_LEGAL,
  FOOTER_NAV_LINKS,
  FOOTER_PRIVACY_HREF,
  FOOTER_SOCIAL_LINKS,
  footerLinkAttrs,
  footerLinkTag,
} from './footerLinks'

const { t } = useI18n()
</script>

<template>
  <footer class="auth-footer" data-test="auth-marketing-footer">
    <div class="auth-footer__glow" aria-hidden="true">
      <div class="auth-footer__glow-bloom" />
    </div>

    <div class="auth-footer__content">
      <div class="auth-footer__visual">
        <img :src="catalystMonogram" alt="" aria-hidden="true" class="auth-footer__monogram" />
      </div>

      <img :src="catalystLogo" alt="" class="auth-footer__logo" />

      <div class="auth-footer__columns">
        <nav class="auth-footer__nav" data-test="auth-footer-nav">
          <component
            :is="footerLinkTag(link.href)"
            v-for="link in FOOTER_NAV_LINKS"
            :key="link.labelKey"
            v-bind="footerLinkAttrs(link.href)"
            class="auth-footer__link"
          >
            {{ t(`auth.ui.footer.nav.${link.labelKey}`) }}
          </component>
        </nav>

        <nav class="auth-footer__social" data-test="auth-footer-social">
          <component
            :is="footerLinkTag(link.href)"
            v-for="link in FOOTER_SOCIAL_LINKS"
            :key="link.label"
            v-bind="footerLinkAttrs(link.href)"
            class="auth-footer__link"
          >
            {{ link.label }}
          </component>
        </nav>

        <address class="auth-footer__contact">
          <a :href="`mailto:${FOOTER_CONTACT.email}`" class="auth-footer__link">
            {{ FOOTER_CONTACT.email }}
          </a>
          <span class="auth-footer__address">{{ FOOTER_CONTACT.address }}</span>
        </address>
      </div>

      <hr class="auth-footer__rule" />

      <div class="auth-footer__legal">
        <span>{{ FOOTER_LEGAL.copyright }}</span>
        <span>{{ FOOTER_LEGAL.companyNumber }}</span>
        <span>{{ FOOTER_LEGAL.vatNumber }}</span>
        <component
          :is="footerLinkTag(FOOTER_PRIVACY_HREF)"
          v-bind="footerLinkAttrs(FOOTER_PRIVACY_HREF)"
          class="auth-footer__link auth-footer__privacy"
          data-test="auth-footer-privacy"
        >
          {{ t('auth.ui.footer.privacy') }}
        </component>
      </div>
    </div>
  </footer>
</template>

<style scoped>
/* Full-bleed band: the layout cancels its own gutter for this element,
 * so the 24px inset is re-applied here. Height is intentionally natural
 * — the monogram is a row of the content stack, not a backdrop, so
 * nothing needs to reserve space for it.
 *
 * Deliberately NOT `overflow: hidden`. The glow window is taller than
 * the footer, so clipping it here severs the bloom at the footer's top
 * edge while it still carries ~8% alpha — a visible horizontal seam
 * against the black section above. catalyst-growth.com leaves its own
 * footer overflow visible for exactly this reason and lets the bloom
 * bleed upward, where the mask fades it out on its own. */
.auth-footer {
  position: relative;
  padding: var(--space-24) 24px var(--space-6);
  isolation: isolate;
}

/* Square viewport-wide window for the bloom. Its own `overflow` does the
 * only clipping that is safe: the sides, at the viewport edges, which
 * keeps the double-width bloom from opening a horizontal scrollbar. The
 * top edge sits a full mask radius from the bloom's centre, so nothing
 * visible is lost there. */
.auth-footer__glow {
  position: absolute;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 0;
  aspect-ratio: 1;
  overflow: hidden;
  pointer-events: none;
}

/* Twice as wide as the window and pulled half its height below the
 * bottom edge, so the circular mask reads as a bloom rising out of the
 * page edge rather than a band across it. */
.auth-footer__glow-bloom {
  position: absolute;
  bottom: 0;
  left: 50%;
  width: 200%;
  aspect-ratio: 1;
  background-image: var(--auth-glow-gradient);
  opacity: 0.3;
  transform: translate(-50%, 50%);
  mask-image: var(--auth-glow-mask);
  -webkit-mask-image: var(--auth-glow-mask);
}

.auth-footer__content {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
}

/* The monogram is the first row of the stack, centred in clear space
 * above the wordmark — in the Figma it occupies y 350-954 of the 1304px
 * frame while the content sits below y 992, and catalyst-growth.com
 * likewise gives it its own flow row. Layering it behind the columns
 * (the pre-fix arrangement) is wrong in both references. */
.auth-footer__visual {
  display: flex;
  align-items: center;
  justify-content: center;
}

.auth-footer__monogram {
  display: block;
  width: 100%;
  max-width: 600px;
  aspect-ratio: 1;
}

/* `align-self` is load-bearing: as a column flex item the image would
 * otherwise stretch to the full content width, and the SVG would centre
 * its artwork inside that box — reading as a centred wordmark rather
 * than the left-aligned one both references show. */
.auth-footer__logo {
  align-self: flex-start;
  display: block;
  width: auto;
  height: 28px;
}

.auth-footer__columns {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-6);
}

.auth-footer__social,
.auth-footer__contact {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}

/* Four rows flowing into columns reproduces the Figma pair of 375px
 * stacks (and its 2x4 mobile grid) from one flat list — no second
 * markup block and no hand-split halves. */
.auth-footer__nav {
  display: grid;
  grid-auto-flow: column;
  grid-template-rows: repeat(4, auto);
  justify-content: start;
  gap: var(--space-5) var(--space-16);
}

.auth-footer__contact {
  align-items: flex-end;
  gap: var(--space-5);
  font-style: normal;
  text-align: right;
}

.auth-footer__link {
  color: var(--auth-page-fg);
  font-size: clamp(16px, 1.04vw, 20px);
  line-height: 1.7;
  text-decoration: none;
  opacity: 0.85;
}

a.auth-footer__link:hover {
  opacity: 1;
}

.auth-footer__address {
  color: var(--auth-page-fg);
  font-size: clamp(16px, 1.04vw, 20px);
  line-height: 1.7;
  opacity: 0.6;
}

.auth-footer__rule {
  width: 100%;
  height: 1px;
  margin: 0;
  border: 0;
  background: var(--auth-hairline);
}

.auth-footer__legal {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2) var(--space-6);
  color: var(--auth-page-fg);
  font-size: 14px;
  line-height: 1.7;
  opacity: 0.5;
}

/* Privacy policy sits opposite the registration details on desktop. */
.auth-footer__privacy {
  margin-left: auto;
  font-size: inherit;
  opacity: 1;
}

@media (max-width: 1100px) {
  .auth-footer__columns {
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-12);
  }

  .auth-footer__social {
    flex-direction: row;
    gap: var(--space-6);
  }

  .auth-footer__contact {
    align-items: flex-start;
    gap: var(--space-2);
    text-align: left;
  }

  .auth-footer__privacy {
    margin-left: 0;
  }
}

/* Phones drop the monogram entirely: the Figma's mobile footer frame
 * has no monogram at all, and catalyst-growth.com hides its own visual
 * row at the same 480px cutoff. At this width it would otherwise crowd
 * the contact block. */
@media (max-width: 479px) {
  .auth-footer__visual {
    display: none;
  }
}
</style>
