/**
 * AH-119 Stage B — one behavioural suite, run against each of the three thread views that carry
 * message edit and delete (`ChatPanel`, `RelationshipThreadView`, the agency fork). The views have
 * three different markup shapes and message types, so each spec supplies a small adapter; the
 * behaviour pinned is the same everywhere:
 *
 *   - the ⋯ menu is on the viewer's OWN, non-deleted messages only, and only with the Stage B switch on;
 *   - Edit is offered inside the 15-minute window of an open thread, never after it or in a closed
 *     thread, and Delete always is (Q12);
 *   - an edit shows the new text and an "edited" mark, calls the transport once, and a refused edit
 *     rolls back with the mapped message;
 *   - a delete asks first, then shows "Message deleted" with no attachments;
 *   - a message that ARRIVES deleted renders the placeholder and hides its attachments;
 *   - an edit that arrives from the other side shows "edited".
 * The flag-off DOM is pinned separately, against the pre-Stage-B markup (`stageBFlagOffPins.json`).
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi, type Mock } from 'vitest'

export interface SuiteMessageInput {
  own: boolean
  body?: string | null
  attachments?: boolean
  editedAt?: string | null
  isDeleted?: boolean
  /** Minutes before now the message was sent. */
  ageMinutes?: number
  kind?: 'text' | 'system'
}

export interface SuiteMount {
  wrapper: VueWrapper
  edit: Mock
  remove: Mock
}

export interface StageBAdapter {
  name: string
  rowTestId: (id: string) => string
  /** A resource of this surface's message shape. */
  makeMessage: (id: string, input: SuiteMessageInput) => unknown
  mount: (options: {
    messages: unknown[]
    features: Record<string, boolean> | undefined
    canSend: boolean
    edit: Mock
    remove: Mock
  }) => Promise<VueWrapper>
}

const ON = { messages_v2_edit_search: true }

function inBody(testId: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-test="${testId}"]`)
}

export function describeStageBActions(adapter: StageBAdapter): void {
  async function setup(
    inputs: Record<string, SuiteMessageInput>,
    options: { features?: Record<string, boolean> | undefined; canSend?: boolean } = {},
  ): Promise<SuiteMount> {
    const edit = vi.fn()
    const remove = vi.fn()
    const features = 'features' in options ? options.features : ON
    const wrapper = await adapter.mount({
      messages: Object.entries(inputs).map(([id, input]) => adapter.makeMessage(id, input)),
      features,
      canSend: options.canSend ?? true,
      edit,
      remove,
    })
    await flushPromises()
    return { wrapper, edit, remove }
  }

  const row = (m: SuiteMount, id: string) => m.wrapper.get(`[data-test="${adapter.rowTestId(id)}"]`)

  async function openMenu(m: SuiteMount, id: string): Promise<void> {
    await row(m, id).get('[data-test="message-actions"]').trigger('click')
    await flushPromises()
  }

  afterEach(() => {
    document.body.innerHTML = ''
  })

  describe(`message actions (AH-119 Stage B) — ${adapter.name}`, () => {
    it('shows the ⋯ menu on own messages only', async () => {
      const m = await setup({
        mine: { own: true, body: 'mine' },
        theirs: { own: false, body: 'theirs' },
      })
      expect(row(m, 'mine').find('[data-test="message-actions"]').exists()).toBe(true)
      expect(row(m, 'theirs').find('[data-test="message-actions"]').exists()).toBe(false)
      m.wrapper.unmount()
    })

    it('shows no ⋯ menu when the Stage B switch is off or absent', async () => {
      const variants: Array<Record<string, boolean> | undefined> = [
        { messages_v2_edit_search: false },
        {},
        undefined,
      ]
      for (const features of variants) {
        const m = await setup({ mine: { own: true, body: 'mine' } }, { features })
        expect(m.wrapper.find('[data-test="message-actions"]').exists()).toBe(false)
        expect(m.wrapper.find('[data-test="message-edited"]').exists()).toBe(false)
        m.wrapper.unmount()
      }
    })

    it('offers Edit inside the window and Delete always', async () => {
      const m = await setup({ mine: { own: true, body: 'mine', ageMinutes: 2 } })
      await openMenu(m, 'mine')
      expect(inBody('message-action-edit')).not.toBeNull()
      expect(inBody('message-action-delete')).not.toBeNull()
      m.wrapper.unmount()
    })

    it('offers no Edit after 15 minutes, but Delete stays', async () => {
      const m = await setup({ old: { own: true, body: 'old', ageMinutes: 20 } })
      await openMenu(m, 'old')
      expect(inBody('message-action-edit')).toBeNull()
      expect(inBody('message-action-delete')).not.toBeNull()
      m.wrapper.unmount()
    })

    it('offers no Edit in a closed thread, but Delete stays (Q12)', async () => {
      const m = await setup(
        { mine: { own: true, body: 'mine', ageMinutes: 1 } },
        { canSend: false },
      )
      await openMenu(m, 'mine')
      expect(inBody('message-action-edit')).toBeNull()
      expect(inBody('message-action-delete')).not.toBeNull()
      m.wrapper.unmount()
    })

    it('edits inline: shows the new text and "edited", and calls the transport once', async () => {
      const m = await setup({ mine: { own: true, body: 'first draft', ageMinutes: 1 } })
      m.edit.mockResolvedValue({
        data: adapter.makeMessage('mine', {
          own: true,
          body: 'second draft',
          ageMinutes: 1,
          editedAt: new Date().toISOString(),
        }),
      })

      await openMenu(m, 'mine')
      inBody('message-action-edit')!.click()
      await flushPromises()

      const field = row(m, 'mine').get('[data-test="message-edit-input"] textarea')
      expect((field.element as HTMLTextAreaElement).value).toBe('first draft')
      await field.setValue('second draft')
      await row(m, 'mine').get('[data-test="message-edit-save"]').trigger('click')
      await flushPromises()

      expect(m.edit).toHaveBeenCalledTimes(1)
      expect(m.edit).toHaveBeenCalledWith('mine', { body: 'second draft' })
      expect(row(m, 'mine').text()).toContain('second draft')
      expect(row(m, 'mine').find('[data-test="message-edit-input"]').exists()).toBe(false)
      expect(row(m, 'mine').get('[data-test="message-edited"]').text()).toBe('edited')
      m.wrapper.unmount()
    })

    it('Save stays off for an unchanged or empty text; Cancel restores the bubble', async () => {
      const m = await setup({ mine: { own: true, body: 'first draft', ageMinutes: 1 } })
      await openMenu(m, 'mine')
      inBody('message-action-edit')!.click()
      await flushPromises()

      const save = () => row(m, 'mine').get('[data-test="message-edit-save"]')
      expect(save().attributes('disabled')).toBeDefined()
      await row(m, 'mine').get('[data-test="message-edit-input"] textarea').setValue('   ')
      expect(save().attributes('disabled')).toBeDefined()

      await row(m, 'mine').get('[data-test="message-edit-cancel"]').trigger('click')
      expect(row(m, 'mine').find('[data-test="message-edit-input"]').exists()).toBe(false)
      expect(row(m, 'mine').text()).toContain('first draft')
      expect(m.edit).not.toHaveBeenCalled()
      m.wrapper.unmount()
    })

    it('rolls back and shows the mapped message when the server refuses the edit', async () => {
      const m = await setup({ mine: { own: true, body: 'first draft', ageMinutes: 1 } })
      m.edit.mockRejectedValue(
        new ApiError({ status: 422, code: 'message.edit_window_closed', message: 'server text' }),
      )

      await openMenu(m, 'mine')
      inBody('message-action-edit')!.click()
      await flushPromises()
      await row(m, 'mine').get('[data-test="message-edit-input"] textarea').setValue('second draft')
      await row(m, 'mine').get('[data-test="message-edit-save"]').trigger('click')
      await flushPromises()

      expect(row(m, 'mine').get('[data-test="message-edit-error"]').text()).toBe(
        'This message can no longer be edited.',
      )
      // The optimistic text was rolled back: no "edited" mark, and the field stays open to cancel.
      expect(row(m, 'mine').find('[data-test="message-edited"]').exists()).toBe(false)
      await row(m, 'mine').get('[data-test="message-edit-cancel"]').trigger('click')
      expect(row(m, 'mine').text()).toContain('first draft')
      expect(row(m, 'mine').text()).not.toContain('second draft')
      m.wrapper.unmount()
    })

    it('deletes after confirming: placeholder shown, attachments gone, transport called once', async () => {
      const m = await setup({
        mine: { own: true, body: 'bye', attachments: true, ageMinutes: 1 },
      })
      m.remove.mockResolvedValue({
        data: adapter.makeMessage('mine', { own: true, body: null, isDeleted: true }),
      })
      expect(row(m, 'mine').find('a[href="https://files.test/doc.pdf"]').exists()).toBe(true)

      await openMenu(m, 'mine')
      inBody('message-action-delete')!.click()
      await flushPromises()
      expect(m.remove).not.toHaveBeenCalled()
      inBody('message-delete-confirm')!.click()
      await flushPromises()

      expect(m.remove).toHaveBeenCalledTimes(1)
      expect(m.remove).toHaveBeenCalledWith('mine')
      expect(row(m, 'mine').get('[data-test="message-deleted"]').text()).toBe('Message deleted')
      expect(row(m, 'mine').text()).not.toContain('bye')
      expect(row(m, 'mine').find('a').exists()).toBe(false)
      expect(row(m, 'mine').find('[data-test="message-actions"]').exists()).toBe(false)
      m.wrapper.unmount()
    })

    it('a message that arrives deleted shows the placeholder and hides its attachments, even if the payload carried them', async () => {
      const m = await setup({
        gone: { own: false, body: 'secret', attachments: true, isDeleted: true },
      })
      expect(row(m, 'gone').get('[data-test="message-deleted"]').text()).toBe('Message deleted')
      expect(row(m, 'gone').text()).not.toContain('secret')
      expect(row(m, 'gone').find('a').exists()).toBe(false)
      expect(row(m, 'gone').find('[data-test="message-actions"]').exists()).toBe(false)
      m.wrapper.unmount()
    })

    it('a deleted message of the OTHER side renders the same placeholder', async () => {
      const m = await setup({ gone: { own: false, body: null, isDeleted: true } })
      expect(row(m, 'gone').find('[data-test="message-deleted"]').exists()).toBe(true)
      m.wrapper.unmount()
    })

    it('an edit made on the other side shows the "edited" mark', async () => {
      const m = await setup({
        theirs: { own: false, body: 'fixed', editedAt: '2026-11-06T09:05:00+00:00' },
        plain: { own: false, body: 'plain' },
      })
      expect(row(m, 'theirs').get('[data-test="message-edited"]').text()).toBe('edited')
      expect(row(m, 'plain').find('[data-test="message-edited"]').exists()).toBe(false)
      m.wrapper.unmount()
    })

    it('system lines never get a menu', async () => {
      const m = await setup({ sys: { own: false, kind: 'system', body: null } })
      expect(m.wrapper.find('[data-test="message-actions"]').exists()).toBe(false)
      m.wrapper.unmount()
    })
  })
}
