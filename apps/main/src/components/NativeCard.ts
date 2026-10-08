/**
 * AH-100b — a Figma "Card" around a run of form fields (surface fill, 1px
 * border, 12px radius, 20px padding, 20px between children; the rules live in
 * `@catalyst/design-tokens/native.css` under `.catalyst-native .native-card`).
 *
 * Renders the card ONLY inside the Capacitor app. On the web it renders its
 * slot alone — a bare Fragment — so the surrounding DOM is exactly what it was
 * before this component existed (the web renders byte-identically, AH-100).
 * Attributes (test ids, classes) are forwarded onto the card.
 */

import { defineComponent, h } from 'vue'

import { isNativePlatform } from '@/core/platform/isNativePlatform'

export default defineComponent({
  name: 'NativeCard',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    const isNative = isNativePlatform()
    return () => {
      const content = slots.default?.()
      return isNative
        ? h('div', { ...attrs, class: ['native-card', attrs.class] }, content)
        : content
    }
  },
})
