<script setup lang="ts">
/**
 * Renders an agency-authored brief field (`offer_description`,
 * `campaigns.description`) with the minimal rich-text set — bold, italic,
 * links, line breaks — sanitized through `renderRichBrief` (AH-081).
 *
 * This is the ONE place in the app that `v-html`s this content, so a future
 * loosening of the sanitizer's allowlist has exactly one call site to
 * audit — not one per render site. Plain fallthrough (no `inheritAttrs:
 * false`): callers keep their existing `data-test`/`data-testid`/`class`
 * on this component exactly as they had it on the `<p>` it replaces, so no
 * render-site conversion in this batch renamed a selector.
 *
 * The outer `<div>` is the template's sole root with NO leading comment —
 * a comment sibling before the root turns it into a multi-root fragment
 * and silently defeats attrs fallthrough. The v-html + its eslint-disable
 * justification live one level down instead, same shape as
 * `AuthFooterMonogram.vue`'s AH-063 precedent.
 */

import { computed } from 'vue'

import { renderRichBrief } from '@/composables/useRichBriefRenderer'

const props = defineProps<{
  /** Markdown-lite source: bold/italic/links/line breaks only. */
  text: string | null | undefined
}>()

const rendered = computed(() => renderRichBrief(props.text ?? ''))
</script>

<template>
  <div class="rich-brief">
    <!-- `rendered` is DOMPurify output from `renderRichBrief`, allowlisted
         to exactly p/br/strong/em/a with http(s)-only hrefs (see
         useRichBriefRenderer.ts) — the sanitize-then-v-html pattern AH-063
         established for a new, justified `v-html` suppression. -->
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div v-html="rendered" />
  </div>
</template>

<style scoped>
.rich-brief :deep(p) {
  margin: 0 0 0.75em;
}

.rich-brief :deep(p:last-child) {
  margin-bottom: 0;
}
</style>
