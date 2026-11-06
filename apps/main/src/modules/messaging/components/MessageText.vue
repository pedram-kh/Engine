<script setup lang="ts">
/**
 * AH-119 (S1) — a message body with clickable links. Segments render as Vue text nodes (never
 * HTML), so message text can't inject markup. A body with no URL renders ONE text node and no
 * wrapper element, so the surrounding bubble DOM is identical to a plain interpolation.
 *
 * On the Capacitor app no external-link helper exists yet (no @capacitor/browser), so the
 * anchor keeps `target="_blank"` in both modes — a device-walk item.
 */
import { type FunctionalComponent, type VNode, computed, createTextVNode, h } from 'vue'

import { linkify } from '@/core/text/linkify'
import type { Segment } from '@/core/text/linkify'

const props = defineProps<{ text: string }>()

const segments = computed(() => linkify(props.text))

// A functional renderer, not a template `v-for`: a template list is a Fragment and Vue brackets
// a Fragment with empty anchor text nodes, which would change the bubble DOM for plain text.
const Segments: FunctionalComponent<{ segments: Segment[] }> = ({
  segments: list,
}): VNode | VNode[] => {
  if (list.length === 0) {
    return createTextVNode('')
  }
  const first = list[0]
  if (list.length === 1 && first?.type === 'text') {
    return createTextVNode(first.text)
  }
  return list.map((segment) =>
    segment.type === 'url'
      ? h(
          'a',
          {
            href: segment.href,
            rel: 'noopener noreferrer',
            target: '_blank',
            class: 'message-link',
            'data-test': 'message-link',
          },
          segment.text,
        )
      : createTextVNode(segment.text),
  )
}
Segments.props = ['segments']
</script>

<template>
  <Segments :segments="segments" />
</template>

<style scoped>
.message-link {
  color: inherit;
  text-decoration: underline;
  text-underline-offset: 2px;
  overflow-wrap: anywhere;
}

.message-link:hover,
.message-link:focus-visible {
  text-decoration-thickness: 2px;
}
</style>
