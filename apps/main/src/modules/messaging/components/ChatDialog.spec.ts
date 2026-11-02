/**
 * AH-116 (item 6) — `ChatDialog` gains a `pinned` slot: content held above the scrolling thread.
 * Pins that it sits OUTSIDE the scroller (`v-card-text`), only while the dialog is open, and that
 * a dialog with no pinned content is unchanged.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'

import ChatDialog from './ChatDialog.vue'

vi.mock('../api/messaging.api', () => ({
  agencyChatTransport: vi.fn(),
  messagingApi: {},
}))

// VDialog teleports to the body; render its content in place so the structure is assertable.
const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}
const ChatPanelStub = {
  name: 'ChatPanel',
  props: ['transport', 'title'],
  template: '<div data-test="chat-panel-stub" />',
}

function mountDialog(open: boolean, withPinned: boolean) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(ChatDialog, {
    props: { modelValue: open, transport: null },
    slots: withPinned ? { pinned: '<p data-test="pinned-content">Files</p>' } : {},
    global: {
      plugins: [i18n, vuetify],
      stubs: { VDialog: VDialogStub, ChatPanel: ChatPanelStub },
    },
  })
}

describe('ChatDialog pinned slot (AH-116)', () => {
  it('draws the pinned content before the scroller, and outside it', () => {
    const wrapper = mountDialog(true, true)

    const pinned = wrapper.get('[data-test="pinned-content"]').element
    const scroller = wrapper.get('.v-card-text').element
    expect(scroller.contains(pinned)).toBe(false)
    expect(pinned.compareDocumentPosition(scroller) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    // The thread itself still lives in the scroller.
    expect(scroller.contains(wrapper.get('[data-test="chat-panel-stub"]').element)).toBe(true)
  })

  it('draws nothing pinned while the dialog is closed', () => {
    const wrapper = mountDialog(false, true)
    expect(wrapper.find('[data-test="pinned-content"]').exists()).toBe(false)
  })

  it('a dialog with no pinned content has no pinned wrapper', () => {
    const wrapper = mountDialog(true, false)
    expect(wrapper.find('[data-test="chat-dialog-pinned"]').exists()).toBe(false)
  })
})
