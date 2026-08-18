/**
 * Vitest coverage for `CreatorProfileDialog` (AH-080) — the thin `v-dialog`
 * shell. The actual profile rendering/mode logic is
 * `CreatorProfileContent.spec.ts`'s job; this pins the wrapper's own
 * contract: it only mounts the content while open (so every open is a fresh
 * load, never a stale carry-over from a previous creator), forwards props
 * through untouched, and the close button emits `update:modelValue`.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import CreatorProfileDialog from './CreatorProfileDialog.vue'

// v-dialog teleports its content to <body> by default, which puts it outside
// the mounted wrapper's queryable tree. The house pattern (BoardColumnDialog,
// BoardAutomationDialog, etc.) is a local stub that renders the slot inline.
const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  emits: ['update:modelValue', 'update:model-value'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

function mountDialog(props: {
  modelValue: boolean
  agencyId?: string
  creatorUlid?: string
  assumeFull?: boolean
}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(CreatorProfileDialog, {
    props: {
      agencyId: 'agency-ulid',
      creatorUlid: '01CREATORULIDXXXXXXXXXXXXXX',
      ...props,
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: { CreatorProfileContent: true, VDialog: VDialogStub },
    },
    attachTo: document.createElement('div'),
  })
}

describe('CreatorProfileDialog (AH-080)', () => {
  it('does not mount CreatorProfileContent while closed', async () => {
    const wrapper = mountDialog({ modelValue: false })
    await flushPromises()

    expect(wrapper.findComponent({ name: 'CreatorProfileContent' }).exists()).toBe(false)
    wrapper.unmount()
  })

  it('mounts CreatorProfileContent while open, forwarding agencyId/creatorUlid/assumeFull', async () => {
    const wrapper = mountDialog({
      modelValue: true,
      agencyId: 'agency-2',
      creatorUlid: 'creator-2',
      assumeFull: true,
    })
    await flushPromises()

    const content = wrapper.findComponent({ name: 'CreatorProfileContent' })
    expect(content.exists()).toBe(true)
    expect(content.props()).toMatchObject({
      agencyId: 'agency-2',
      creatorUlid: 'creator-2',
      assumeFull: true,
    })
    wrapper.unmount()
  })

  it('emits update:modelValue(false) when the close button is clicked', async () => {
    const wrapper = mountDialog({ modelValue: true })
    await flushPromises()

    await wrapper.find('[data-test="creator-profile-dialog-close"]').trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([[false]])
    wrapper.unmount()
  })

  it('forwards the dialog scrim/backdrop close (update:model-value) upward', async () => {
    const wrapper = mountDialog({ modelValue: true })
    await flushPromises()

    const dialog = wrapper.findComponent({ name: 'VDialog' })
    dialog.vm.$emit('update:model-value', false)
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toEqual([[false]])
    wrapper.unmount()
  })
})
