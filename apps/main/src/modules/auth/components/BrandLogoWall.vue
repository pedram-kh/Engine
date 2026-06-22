<script setup lang="ts">
/**
 * Partner brand logo wall for the rebrand sign-in landing (Figma
 * "Rebrand" node 359-1253): a 5×2 grid of partner logos in bordered
 * cells with a gradient hover state. The SVGs were exported from the
 * Figma frame (white wordmarks on transparency, one full-cell viewBox
 * each, borders stripped — the cell border is CSS so it can collapse
 * and carry the hover state).
 */

import boltLogo from '@/modules/auth/assets/brands/bolt.svg'
import canvaLogo from '@/modules/auth/assets/brands/canva.svg'
import granolaLogo from '@/modules/auth/assets/brands/granola.svg'
import huelLogo from '@/modules/auth/assets/brands/huel.svg'
import perplexityLogo from '@/modules/auth/assets/brands/perplexity.svg'
import purdyFiggLogo from '@/modules/auth/assets/brands/purdy-figg.svg'
import runnaLogo from '@/modules/auth/assets/brands/runna.svg'
import wildLogo from '@/modules/auth/assets/brands/wild.svg'
import yonderLogo from '@/modules/auth/assets/brands/yonder.svg'
import zoeLogo from '@/modules/auth/assets/brands/zoe.svg'

/** Order mirrors the Figma frame: row 1 then row 2, left to right. */
const brands: ReadonlyArray<{ name: string; src: string; href: string }> = [
  {
    name: 'Perplexity',
    src: perplexityLogo,
    href: 'https://www.catalyst-growth.com/work/perplexity',
  },
  { name: 'Huel', src: huelLogo, href: 'https://www.catalyst-growth.com/work/huel' },
  { name: 'Bolt', src: boltLogo, href: 'https://www.catalyst-growth.com/work/bolt-food' },
  { name: 'Wild', src: wildLogo, href: 'https://www.catalyst-growth.com/work/wild' },
  {
    name: 'Purdy & Figg',
    src: purdyFiggLogo,
    href: 'https://www.catalyst-growth.com/work/purdy-figg',
  },
  { name: 'Zoe', src: zoeLogo, href: 'https://www.catalyst-growth.com/work/zoe' },
  { name: 'Canva', src: canvaLogo, href: 'https://www.catalyst-growth.com/work/canva' },
  { name: 'Runna', src: runnaLogo, href: 'https://www.catalyst-growth.com/work/runna' },
  { name: 'Yonder', src: yonderLogo, href: 'https://www.catalyst-growth.com/work/yonder' },
  { name: 'Granola', src: granolaLogo, href: 'https://www.catalyst-growth.com/work/granola-ai' },
]
</script>

<template>
  <div class="brand-wall" data-test="auth-brand-wall">
    <a
      v-for="brand in brands"
      :key="brand.name"
      :href="brand.href"
      target="_blank"
      rel="noopener noreferrer"
      class="brand-wall__cell"
    >
      <img :src="brand.src" :alt="brand.name" class="brand-wall__logo" loading="lazy" />
    </a>
  </div>
</template>

<style scoped>
.brand-wall {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  /* Collapsed 1px grid: wall owns top+left, cells own right+bottom. */
  border-top: 1px solid var(--auth-cell-border);
  border-left: 1px solid var(--auth-cell-border);
}

.brand-wall__cell {
  position: relative;
  /* Cell proportions from the Figma frame (375.6 × 194.6). */
  aspect-ratio: 375.6 / 194.6;
  border-right: 1px solid var(--auth-cell-border);
  border-bottom: 1px solid var(--auth-cell-border);
  cursor: pointer;
  text-decoration: none;
}

.brand-wall__cell:hover {
  background: var(--auth-cell-hover-bg);
}

.brand-wall__cell:hover::after {
  content: '';
  position: absolute;
  inset: 0;
  border: 1px solid transparent;
  border-image: var(--auth-cell-hover-border) 1;
  pointer-events: none;
}

.brand-wall__logo {
  display: block;
  width: 100%;
  height: 100%;
}

@media (max-width: 900px) {
  .brand-wall {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
