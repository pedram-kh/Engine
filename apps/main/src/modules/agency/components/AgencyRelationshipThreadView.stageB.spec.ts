/**
 * AH-119 Stage B — message edit and delete on the agency fork
 * (`AgencyRelationshipThreadView`, both transports: Direct and campaign). The behaviour is the shared suite in `stageBActionsSuite.ts`; the
 * flag-off DOM pins are in `AgencyRelationshipThreadView.spec.ts`.
 */

import type { AgencyThreadMessage } from '@/modules/messaging/api/agencyThreadTransport'
import { vi } from 'vitest'

import {
  describeStageBActions,
  type StageBAdapter,
  type SuiteMessageInput,
} from '../../../../tests/unit/helpers/stageBActionsSuite'
import { mountView } from '../../../../tests/unit/helpers/stageBFlagOff'
import AgencyRelationshipThreadView from '@/modules/agency/components/AgencyRelationshipThreadView.vue'

const PDF = {
  kind: 'file' as const,
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
  name: 'AgencyRelationshipThreadView',
  rowTestId: (id) => `relationship-message-${id}`,
  makeMessage: (id: string, input: SuiteMessageInput): AgencyThreadMessage => ({
    id,
    type: 'relationship_message',
    attributes: {
      kind: input.kind ?? 'text',
      sender_role: input.own ? 'agency_user' : 'creator',
      system_event_key: input.kind === 'system' ? 'assignment.accepted' : null,
      body: input.body === undefined ? 'hello' : input.body,
      attachments: input.attachments === true ? [PDF] : [],
      is_own: input.own,
      sender: { name: 'Op' },
      read_by_counterparty: input.own ? false : null,
      created_at: sentAt(input.ageMinutes),
      edited_at: input.editedAt ?? null,
      is_deleted: input.isDeleted ?? false,
    },
  }),
  mount: ({ messages, features, canSend, edit, remove }) =>
    mountView(
      AgencyRelationshipThreadView,
      {
        title: 'Agency',
        transport: {
          list: vi.fn().mockResolvedValue({
            data: messages,
            meta: {
              thread: {
                id: 'thread',
                last_message_at: null,
                unread_count: 0,
                can_send: canSend,
                closed_reason: canSend ? null : 'relation_ended',
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
