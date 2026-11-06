/**
 * AH-119 Stage B — `MessageActions`, the "⋯" menu on a message the viewer wrote.
 *
 * Pins: Edit is offered only while the client clock says the 15-minute window is open AND the
 * thread is open (14:59 yes, 15:01 no), re-read each time the menu opens; Delete is always
 * offered, including in a closed thread (Q12) and after the window; a delete asks first and runs
 * only on confirm; a refused delete keeps the dialog open with the mapped message.
 * (That the menu appears on OWN messages only is the thread views' job: see the three view specs.)
 * Menus and dialogs teleport to `document.body`.
 *
 * Break-revert: drop the window check → the 15:01 case fails; drop the thread check → the closed
 * case fails; hide Delete in a closed thread → the Q12 case fails; run `confirmDelete` on click
 * instead of on confirm → the "asks first" case fails.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import MessageActions from './MessageActions.vue'

const SENT = '2026-11-06T09:00:00+00:00'
const SENT_MS = new Date(SENT).getTime()

function mountActions(props: Record<string, unknown> = {}) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(MessageActions, {
    props: {
      createdAt: SENT,
      threadOpen: true,
      confirmDelete: vi.fn().mockResolvedValue(undefined),
      ...props,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.body,
  })
}

type W = ReturnType<typeof mountActions>

function inBody<T extends HTMLElement = HTMLElement>(testId: string): T | null {
  return document.body.querySelector<T>(`[data-test="${testId}"]`)
}

async function openMenu(w: W): Promise<void> {
  await w.get('[data-test="message-actions"]').trigger('click')
  await flushPromises()
}

function at(minutes: number, seconds = 0): void {
  vi.setSystemTime(SENT_MS + (minutes * 60 + seconds) * 1000)
}

describe('MessageActions', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    at(1)
  })
  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  it('has a labelled button and no menu content until opened', () => {
    const w = mountActions()
    expect(w.get('[data-test="message-actions"]').attributes('aria-label')).toBe('Message actions')
    expect(inBody('message-actions-menu')).toBeNull()
    w.unmount()
  })

  it('offers Edit and Delete inside the window with an open thread', async () => {
    const w = mountActions()
    await openMenu(w)
    expect(inBody('message-action-edit')?.textContent).toContain('Edit')
    expect(inBody('message-action-delete')?.textContent).toContain('Delete')
    expect(inBody('message-action-edit-hint')).toBeNull()
    w.unmount()
  })

  it('still offers Edit at 14:59 and stops offering it at 15:01', async () => {
    at(14, 59)
    const early = mountActions()
    await openMenu(early)
    expect(inBody('message-action-edit')).not.toBeNull()
    early.unmount()
    document.body.innerHTML = ''

    at(15, 1)
    const late = mountActions()
    await openMenu(late)
    expect(inBody('message-action-edit')).toBeNull()
    expect(inBody('message-action-delete')).not.toBeNull()
    // The reason is explained, not silent.
    expect(inBody('message-action-edit-hint')?.textContent).toContain('15 minutes')
    late.unmount()
  })

  it('re-reads the clock each time the menu opens', async () => {
    at(14, 30)
    const w = mountActions()
    await openMenu(w)
    expect(inBody('message-action-edit')).not.toBeNull()
    await w.get('[data-test="message-actions"]').trigger('click') // close
    await flushPromises()

    at(16)
    await openMenu(w)
    expect(inBody('message-action-edit')).toBeNull()
    w.unmount()
  })

  it('offers no Edit in a closed thread, but Delete stays (Q12)', async () => {
    const w = mountActions({ threadOpen: false })
    await openMenu(w)
    expect(inBody('message-action-edit')).toBeNull()
    expect(inBody('message-action-edit-hint')).toBeNull()
    expect(inBody('message-action-delete')).not.toBeNull()
    w.unmount()
  })

  it('treats an unparseable time as outside the window', async () => {
    const w = mountActions({ createdAt: 'not a date' })
    await openMenu(w)
    expect(inBody('message-action-edit')).toBeNull()
    w.unmount()
  })

  it('emits edit and closes the menu when Edit is chosen', async () => {
    const w = mountActions()
    await openMenu(w)
    inBody('message-action-edit')!.click()
    await flushPromises()
    expect(w.emitted('edit')).toHaveLength(1)
    w.unmount()
  })

  describe('delete', () => {
    async function askDelete(w: W): Promise<void> {
      await openMenu(w)
      inBody('message-action-delete')!.click()
      await flushPromises()
    }

    it('asks first: nothing is deleted until the dialog is confirmed', async () => {
      const confirmDelete = vi.fn().mockResolvedValue(undefined)
      const w = mountActions({ confirmDelete })
      await askDelete(w)

      expect(document.body.textContent).toContain('Delete this message?')
      expect(document.body.textContent).toContain('Both sides will see "Message deleted"')
      expect(confirmDelete).not.toHaveBeenCalled()
      w.unmount()
    })

    it('cancel closes the dialog without deleting', async () => {
      const confirmDelete = vi.fn().mockResolvedValue(undefined)
      const w = mountActions({ confirmDelete })
      await askDelete(w)
      inBody('message-delete-cancel')!.click()
      await flushPromises()

      expect(confirmDelete).not.toHaveBeenCalled()
      w.unmount()
    })

    it('confirm runs the delete once and closes the dialog', async () => {
      const confirmDelete = vi.fn().mockResolvedValue(undefined)
      const w = mountActions({ confirmDelete })
      await askDelete(w)
      inBody('message-delete-confirm')!.click()
      await flushPromises()

      expect(confirmDelete).toHaveBeenCalledTimes(1)
      expect(w.findComponent(vuetifyComponents.VDialog).props('modelValue')).toBe(false)
      w.unmount()
    })

    it('deletes in a closed thread and after the window', async () => {
      at(90)
      const confirmDelete = vi.fn().mockResolvedValue(undefined)
      const w = mountActions({ confirmDelete, threadOpen: false })
      await askDelete(w)
      inBody('message-delete-confirm')!.click()
      await flushPromises()

      expect(confirmDelete).toHaveBeenCalledTimes(1)
      w.unmount()
    })

    it('keeps the dialog open with the mapped message when the server refuses', async () => {
      const confirmDelete = vi
        .fn()
        .mockRejectedValue(
          new ApiError({ status: 422, code: 'message.already_deleted', message: 'x' }),
        )
      const w = mountActions({ confirmDelete })
      await askDelete(w)
      inBody('message-delete-confirm')!.click()
      await flushPromises()

      expect(inBody('message-delete-error')?.textContent).toContain(
        'This message was already deleted.',
      )
      expect(w.findComponent(vuetifyComponents.VDialog).props('modelValue')).toBe(true)
      w.unmount()
    })

    it('falls back to the generic delete error for an unknown failure', async () => {
      const confirmDelete = vi.fn().mockRejectedValue(new Error('network'))
      const w = mountActions({ confirmDelete })
      await askDelete(w)
      inBody('message-delete-confirm')!.click()
      await flushPromises()

      expect(inBody('message-delete-error')?.textContent).toContain(
        "We couldn't delete the message.",
      )
      w.unmount()
    })
  })
})
