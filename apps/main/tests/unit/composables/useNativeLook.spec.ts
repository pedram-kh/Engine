/**
 * AH-115 (L1.3) — `useNativeLook()`: the look question, separate from the platform question.
 *
 *   - outside the creator shell (no `CREATOR_LOOK` provided: the agency, the admin, a bare
 *     page) it is false, so a shared component gated on it renders exactly as before;
 *   - below a creator-side layout it is true on the web (the layout provides it) and follows
 *     the route gate `AuthLayout` feeds it;
 *   - inside the Capacitor app it is true regardless.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, provide, ref, type Ref } from 'vue'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({ isNativePlatform: () => platform.native }))

import { CREATOR_LOOK, useNativeLook } from '@/composables/useNativeLook'

function probe(provided: Ref<boolean> | null) {
  let look: { value: boolean } | undefined
  const Child = defineComponent({
    setup() {
      look = useNativeLook()
      return () => h('div')
    },
  })
  const Parent = defineComponent({
    setup() {
      if (provided !== null) provide(CREATOR_LOOK, provided)
      return () => h(Child)
    },
  })
  const wrapper = mount(Parent)
  return { wrapper, look: () => look?.value }
}

describe('useNativeLook', () => {
  it('is false when no creator layout provides the look (agency, admin, bare page)', () => {
    platform.native = false
    const p = probe(null)
    expect(p.look()).toBe(false)
    p.wrapper.unmount()
  })

  it('is true below a creator layout and follows its gate', async () => {
    platform.native = false
    const gate = ref(true)
    const p = probe(gate)
    expect(p.look()).toBe(true)
    gate.value = false
    await p.wrapper.vm.$nextTick()
    expect(p.look()).toBe(false)
    p.wrapper.unmount()
  })

  it('is true inside the native app whatever the layout says', () => {
    platform.native = true
    expect(probe(null).look()).toBe(true)
    expect(probe(ref(false)).look()).toBe(true)
    platform.native = false
  })
})
