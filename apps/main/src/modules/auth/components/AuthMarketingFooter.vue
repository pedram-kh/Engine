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
  FOOTER_SOCIAL_LINKS,
  footerLinkTag,
} from './footerLinks'

const { t } = useI18n()
</script>

<template>
  <footer class="auth-footer" data-test="auth-marketing-footer">
    <img :src="catalystMonogram" alt="" aria-hidden="true" class="auth-footer__monogram" />

    <div class="auth-footer__content">
      <img :src="catalystLogo" alt="" class="auth-footer__logo" />

      <div class="auth-footer__columns">
        <nav class="auth-footer__nav" data-test="auth-footer-nav">
          <component
            :is="footerLinkTag(link.href)"
            v-for="link in FOOTER_NAV_LINKS"
            :key="link.labelKey"
            :href="link.href"
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
            :href="link.href"
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
          :is="footerLinkTag(null)"
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
 * so the 24px inset is re-applied here. Height tracks the Figma frame
 * (1304px on 1920px) to leave the watermark room; the content is pinned
 * to the bottom edge above it. */
.auth-footer {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  min-height: clamp(560px, 67.9vw, 1304px);
  padding: var(--space-24) 24px var(--space-6);
  overflow: hidden;
  isolation: isolate;
}

/* Aurora band rising from the bottom edge, mirroring the band the
 * layout paints along the top of the page. */
.auth-footer::before {
  content: '';
  position: absolute;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: -2;
  height: 450px;
  background: var(--auth-glow-gradient);
  mask-image: linear-gradient(to top, black, transparent);
  -webkit-mask-image: linear-gradient(to top, black, transparent);
  pointer-events: none;
}

/* Oversized watermark, centred horizontally and bled off the bottom —
 * 603px on the 1920px Figma frame. */
.auth-footer__monogram {
  position: absolute;
  bottom: 0;
  left: 50%;
  z-index: -1;
  width: clamp(320px, 31.4vw, 604px);
  transform: translateX(-50%);
  pointer-events: none;
}

.auth-footer__content {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
}

.auth-footer__logo {
  display: block;
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
</style>
