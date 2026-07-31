<script setup lang="ts">
/**
 * The oversized Catalyst monogram in the sign-in footer.
 *
 * The artwork is inlined rather than rendered through `<img>` because the
 * motion has to reach individual nodes inside it — an `<img>` is an opaque
 * box to both CSS and script. It stays a `.svg` asset imported `?raw`
 * (the same shape `EnableTotpPage` uses for the TOTP code) rather than
 * being pasted into this file: its ~20 gradient stops are colour literals,
 * and `no-hard-coded-colors` scans every `.vue` against an allowlist that
 * is empty by design. Keeping the artwork out of the SFC satisfies that
 * invariant without an allowlist entry.
 *
 * Four effects, matching catalyst-growth.com: two counter-rotating orbs
 * inside the card, a sheen pulse across its top, a 3D tilt that follows
 * the cursor, and a glare that slides with it. The orbits and the sheen
 * are declarative CSS; only the two cursor-driven effects need script,
 * and that is confined to writing custom properties which CSS eases.
 *
 * Reduced motion is handled entirely in CSS, so there is no runtime branch
 * to keep covered: the listeners still write their custom properties and
 * the stylesheet declines to act on them.
 */

import { ref } from 'vue'

import monogramMarkup from '../assets/catalyst-monogram.svg?raw'
import { MONOGRAM_TILT_REST, monogramTiltFor, type MonogramTilt } from './monogramTilt'

const tilt = ref<MonogramTilt>(MONOGRAM_TILT_REST)

/** Whether the pointer is over the artwork, which is what reveals the glare. */
const engaged = ref(false)

function onPointerMove(event: PointerEvent): void {
  // `currentTarget` is by definition the element this listener is bound
  // to, so the cast needs no runtime guard behind it — and adding one
  // would leave an unreachable branch that the coverage gate cannot pass.
  const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect()
  tilt.value = monogramTiltFor(bounds, event.clientX, event.clientY)
  engaged.value = true
}

function onPointerLeave(): void {
  tilt.value = MONOGRAM_TILT_REST
  engaged.value = false
}
</script>

<template>
  <div
    class="monogram"
    :class="{ 'monogram--engaged': engaged }"
    :style="{
      '--monogram-rotate-x': `${tilt.rotateX}deg`,
      '--monogram-rotate-y': `${tilt.rotateY}deg`,
      '--monogram-glare-x': `${tilt.glareX}%`,
      '--monogram-glare-y': `${tilt.glareY}%`,
    }"
    data-test="auth-footer-monogram"
    @pointermove="onPointerMove"
    @pointerleave="onPointerLeave"
  >
    <!-- Build-time asset with no runtime input, so there is nothing to
         sanitise. It must be inlined for the CSS to reach inside it. -->
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div class="monogram__art" v-html="monogramMarkup" />
  </div>
</template>

<style scoped>
/* `:deep` throughout: the artwork arrives via v-html, so it carries no
 * scope attribute of its own and is only reachable as a descendant. */
.monogram {
  width: 100%;
  max-width: 600px;
}

.monogram__art {
  display: contents;
}

.monogram :deep(svg) {
  display: block;
  width: 100%;
  height: auto;
  /* The perspective is applied as a transform function rather than the
   * `perspective` property, which only reaches an element's direct
   * children — the artwork sits one level deeper than that. Without it
   * the rotations flatten into a plain skew. */
  transform: perspective(900px) rotateX(var(--monogram-rotate-x, 0deg))
    rotateY(var(--monogram-rotate-y, 0deg));
  /* Settles toward the cursor quickly, then springs a little past level
   * on the way back — the overshoot in the curve stands in for the
   * elastic ease the live site gets from its animation library. */
  transition: transform 0.7s cubic-bezier(0.22, 1.4, 0.36, 1);
}

.monogram--engaged :deep(svg) {
  transition-duration: 0.45s;
  transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
}

.monogram :deep(.cm-glare) {
  opacity: 0;
  transform: translate(var(--monogram-glare-x, 0%), var(--monogram-glare-y, 0%));
  transition:
    opacity 0.45s ease-out,
    transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
}

.monogram--engaged :deep(.cm-glare) {
  opacity: 0.1;
}

/* Both orbs pivot about the card's centre (300,300 in the viewBox), one
 * each way, at durations coprime enough that the pairing never visibly
 * repeats. `transform-box` is required for SVG children to resolve a
 * transform origin in user units. */
.monogram :deep(.cm-orbit-purple),
.monogram :deep(.cm-orbit-teal) {
  transform-box: view-box;
  transform-origin: 300px 300px;
}

.monogram :deep(.cm-orbit-purple) {
  animation: monogram-orbit 14s linear infinite;
}

.monogram :deep(.cm-orbit-teal) {
  animation: monogram-orbit 18s linear infinite reverse;
}

.monogram :deep(.cm-sheen) {
  animation: monogram-sheen 6s ease-in-out infinite;
}

@keyframes monogram-orbit {
  to {
    transform: rotate(360deg);
  }
}

@keyframes monogram-sheen {
  0%,
  100% {
    opacity: 0.12;
  }

  50% {
    opacity: 0.22;
  }
}

/* Everything above is decoration; none of it carries meaning, so it all
 * stops rather than being slowed down. */
@media (prefers-reduced-motion: reduce) {
  .monogram :deep(svg),
  .monogram :deep(.cm-glare) {
    transform: none;
    transition: none;
  }

  .monogram :deep(.cm-glare) {
    opacity: 0;
  }

  .monogram :deep(.cm-orbit-purple),
  .monogram :deep(.cm-orbit-teal),
  .monogram :deep(.cm-sheen) {
    animation: none;
  }
}
</style>
