/**
 * AH-119 Stage B — message edit and delete on the campaign thread (`ChatPanel`, the creator's
 * inline thread and the legacy agency Messages dialog). The behaviour is the shared suite in
 * `stageBActionsSuite.ts`; the flag-off DOM pins are in `ChatPanel.native.spec.ts`.
 */

import type { MessageResource } from '@catalyst/api-client'
import { vi } from 'vitest'

import {
  describeStageBActions,
  type StageBAdapter,
  type SuiteMessageInput,
} from '../../../../tests/unit/helpers/stageBActionsSuite'
import { mountView } from '../../../../tests/unit/helpers/stageBFlagOff'
import ChatPanel from './ChatPanel.vue'

const PDF = {
  s3_path: 'p',
  mime_type: 'application/pdf',
  name: 'doc.pdf',
  size_bytes: 1000,
  view_url: 'https://files.test/doc.pdf',
}

function sentAt(ageMinutes = 1): string {
  return new Date(Date.now() - ageMinutes * 60_000).toISOString()
}

const adapter: StageBAdapter = {
  name: 'ChatPanel',
  rowTestId: (id) => `chat-message-${id}`,
  makeMessage: (id: string, input: SuiteMessageInput): MessageResource => ({
    id,
    type: 'message',
    attributes: {
      kind: input.kind ?? 'text',
      sender_role: input.own ? 'agency_user' : 'creator',
      body: input.body === undefined ? 'hello' : input.body,
      attachments: input.attachments === true ? [PDF] : [],
      system_event_key: input.kind === 'system' ? 'assignment.accepted' : null,
      system_event_data: null,
      is_own: input.own,
      sender: { name: 'Op' },
      created_at: sentAt(input.ageMinutes),
      edited_at: input.editedAt ?? null,
      is_deleted: input.isDeleted ?? false,
    },
  }),
  mount: ({ messages, features, canSend, edit, remove }) =>
    mountView(
      ChatPanel,
      {
        transport: {
          list: vi.fn().mockResolvedValue({
            data: messages,
            meta: {
              thread: {
                id: 'thread',
                assignment_id: 'assignment',
                last_message_at: null,
                unread_count: 0,
                human_send_blocked: !canSend,
              },
              has_more: false,
            },
          }),
          send: vi.fn(),
          markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
          attachmentInit: vi.fn(),
          attachmentComplete: vi.fn(),
          edit,
          remove,
        },
      },
      { features },
    ),
}

describeStageBActions(adapter)
