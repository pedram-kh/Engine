/**
 * AH-114 spec 5 — `shared-components-frozen`.
 *
 * The 21 `.vue` files below are in BOTH the agency and the creator import
 * closures (measured on `4780b8a6`, inventory §7.1): the creator web and the
 * creator native app render them. Ruling 9 (a) of AH-114 is that the agency
 * restyle edits NONE of them; agency-specific DOM is an agency-only fork
 * (`AgencyRelationshipInbox`, `AgencyRelationshipThreadView`,
 * `AgencyKpiCard`, `AgencyNotificationBell`) and everything else is scoped
 * CSS under the agency theme classes. `CKpiCard.vue` is added because the
 * admin dashboard renders it (it is not creator-shared).
 *
 * This is a content-hash pin, not a DOM snapshot: with no file edited the
 * hash is exact and cheaper than a render. It REPLACES the "polarity pin"
 * form proposed in the inventory (plan §9, ruling on §8 item 7).
 *
 * AH-115 (the creator restyle) is the chunk that is MEANT to change these
 * files. It updates the hashes on purpose, in the same commit as the edit.
 * Anyone else who sees this fail has edited a file the creator app renders.
 * Hashes are over the file text with CRLF normalised to LF.
 */

import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')

function sha256(file: string): string {
  const text = readFileSync(path.join(REPO_ROOT, file), 'utf8').replace(/\r\n/g, '\n')
  return createHash('sha256').update(text).digest('hex')
}

const FROZEN_COMPONENTS: ReadonlyArray<readonly [string, string]> = [
  [
    'apps/main/src/components/RichBrief.vue',
    '7f24a6898808a19b3ea0b05c90f189d7cb8af9463c9e04b3192cd376e3dadc3d',
  ],
  [
    'apps/main/src/components/ThemeToggle.vue',
    '37ec7a16dd2f4b0788f174ad45102cd89e87c96507055c7780102c8e20ce56c2',
  ],
  [
    'apps/main/src/modules/impersonation/components/ImpersonationBanner.vue',
    'b7a56fd099ccdd60c1e944d87260f3ff624c431985e05e453df2c3b8c25d8338',
  ],
  [
    // AH-119 Stage B: edit / delete actions, "edited" tag and deleted placeholder behind messages_v2_edit_search — intended edit, hash re-pinned (flag off renders the pre-Stage-B DOM, pinned in stageBFlagOffPins.json).
    'apps/main/src/modules/messaging/components/ChatPanel.vue',
    '441311a723d1880a795ea16490948b9e5d486045f6741bdd051ee974dacc6ab9',
  ],
  [
    'apps/main/src/modules/messaging/components/ContactPicker.vue',
    'ae49c2d76f8a77a101f8e45686e93465bf2105b7ea06a5d7dc394e8a77ce8c63',
  ],
  [
    'apps/main/src/modules/messaging/components/RelationshipInbox.vue',
    '1766bf603c46ab7ac0b8684d6d6b58e2b655904a0f7795fbfd9a68578e6cef05',
  ],
  [
    // AH-119 Stage B: edit / delete actions, "edited" tag and deleted placeholder behind messages_v2_edit_search — intended edit, hash re-pinned (flag off renders the pre-Stage-B DOM, pinned in stageBFlagOffPins.json).
    'apps/main/src/modules/messaging/components/RelationshipThreadView.vue',
    'ee3a912d36cecbe3c7807d4ab79e50b0ff61714a5c0ef99aa133fcc68f2f2711',
  ],
  [
    'apps/main/src/modules/messaging/components/ReportFallback.vue',
    'b205c540fa690d04cdefb9efbef09ef65da014ab6c9d80934cceca68e8243733',
  ],
  [
    'apps/main/src/modules/notifications/components/NotificationBell.vue',
    'bba8172e5afc7fa3f5dbf61036c32cb674a03db508849f0ddfe4e3a0ae8a26f7',
  ],
  [
    // AH-118 C5: offer_edited body money — intended edit, hash re-pinned.
    'apps/main/src/modules/notifications/components/NotificationCenter.vue',
    'a8f7b2c83d55dd54e71008ae0b3f6bd86c26dcec7433ef286050ad1bd6b9303a',
  ],
  [
    'apps/main/src/modules/notifications/components/PushStateRow.vue',
    '4f521041689f0a54156821b92db43c8faab301e4cd71ac7e78c0373ddd4449da',
  ],
  [
    'apps/main/src/modules/notifications/pages/NotificationPreferencesPage.vue',
    '92c3c0748f308e0af6b5059fcfc5903b7d4f5677bf4f643767a248c39d06768f',
  ],
  [
    'apps/main/src/modules/notifications/pages/NotificationsPage.vue',
    '17c1dd57352bbf706398d846707ac5531d47ff05e5cbefd422795c1f40987555',
  ],
  [
    'packages/ui/src/components/CEmptyState.vue',
    '2b5af7875d69336708de2e6bcbf97c63e30a97db1ada454cd877f1754de8532f',
  ],
  [
    'packages/ui/src/components/CMonthGrid.vue',
    '38930847abf58a2b4dce105ba1a560cf5ff00df34d9115da22b500627f16a524',
  ],
  [
    'packages/ui/src/components/CategoryChips.vue',
    '39e7935a8416eac0bba4b13e51e807d30fd89b0fe84258b5cb2ae2ba676ff423',
  ],
  [
    'packages/ui/src/components/CompletenessBar.vue',
    '7f27a5da62315ea08a70331d4538b2f7cdcef83486ce350cc8bd73a8ebeb9de7',
  ],
  [
    'packages/ui/src/components/CountryDisplay.vue',
    '126ea11fb8b9b24d3cf25d0693eea5564756d902678d41d452a38ce09d9e6bde',
  ],
  [
    'packages/ui/src/components/LanguageList.vue',
    '2adff5451876b45942304aec203ff763fcb4acda8973bf0a607e04361497a544',
  ],
  [
    'packages/ui/src/components/PortfolioGallery.vue',
    '7f28bf34578bcdfaf712d49981919ce69ee8805fbd5dc0baf9dd4774314367df',
  ],
  [
    'packages/ui/src/components/SocialAccountList.vue',
    'b208d04c884d246d03f8fa9469e463270fecb0b7fe723ab7cb6ad4ed4b8659f1',
  ],
  [
    'packages/ui/src/components/CKpiCard.vue',
    'c30227897fb32b1d5cfa0b3fae915c102f06eeae492bdc814327ce125a8a42cf',
  ],
]

describe('shared components are frozen (AH-114 spec 5)', () => {
  it('pins the 21 creator-shared files and CKpiCard', () => {
    expect(FROZEN_COMPONENTS).toHaveLength(22)
  })

  it.each(FROZEN_COMPONENTS)('%s is byte-for-byte unchanged', (file, hash) => {
    expect(sha256(file)).toBe(hash)
  })
})
