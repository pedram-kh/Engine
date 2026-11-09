/**
 * AH-115 L0.2 — `agency-frozen`.
 *
 * AH-115 (the creator restyle) must leave the AGENCY shell byte-identical: the agency
 * theme, its forks, `agency.css` and the agency layout are NOT edited. This is the
 * AH-114 `shared-components-frozen` idea turned the other way round: that spec froze
 * the creator-shared files while the agency was restyled; this one freezes the agency
 * module while the creator is restyled.
 *
 * The 22 non-spec files under `apps/main/src/modules/agency/` are content-hash pinned
 * (file text, CRLF normalised to LF; recorded on `5677b378`). The set is also pinned, so
 * a new file in the agency module fails here until it is pinned on purpose. The render
 * itself is proved by the agency baseline walk (`docs/reviews/ah-115-agency-baseline/`,
 * 102 targets: DOM + computed styles + theme `<style>` bytes), which is the real
 * guarantee; this spec is the cheap early alarm.
 *
 * Anyone who sees this fail has edited the agency shell. Outside AH-115 that can be
 * intended: re-pin the file in the same commit and say why.
 */

import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')
const AGENCY_DIR = 'apps/main/src/modules/agency'

function sha256(file: string): string {
  const text = readFileSync(path.join(REPO_ROOT, file), 'utf8').replace(/\r\n/g, '\n')
  return createHash('sha256').update(text).digest('hex')
}

function listAgencyFiles(dir: string): string[] {
  const out: string[] = []
  for (const name of readdirSync(path.join(REPO_ROOT, dir))) {
    const rel = `${dir}/${name}`
    if (statSync(path.join(REPO_ROOT, rel)).isDirectory()) out.push(...listAgencyFiles(rel))
    else if (!/\.spec\.ts$/.test(name)) out.push(rel)
  }
  return out.sort()
}

const FROZEN_AGENCY_FILES: ReadonlyArray<readonly [string, string]> = [
  [
    'apps/main/src/modules/agency/components/AgencyBrandMark.vue',
    'fefc3cb7b35381887626beda7d8a2d3f7b645f57e215bd2a557eacb192853b01',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyCreatorCard.vue',
    'b4c5783985ce0255a9597064ed8e2d23afe2a9247923afcddcd22de74e32a33e',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyFilterPill.vue',
    '08aa2123a41eb0df7fbbea722a5acf57675a03f7012b515a9b01e8a62ac1d8ab',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyKpiCard.vue',
    '432a5ab36234b5bd88d576b79e3ee12e66a074682412dc516990d5cd0715253e',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyNotificationBell.vue',
    '45758d632e89eda29aae4195948b5940f46b1e51e2e7355e137e9f402481adb2',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyPageHeader.vue',
    '4f6ab66cd19ed1d46e083bae57ea059f538c6fcbc1581d3e2db3525bea51e2c5',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyRelationshipInbox.vue',
    '5b6df778eb7a54014d27b6d9f81ec3499cd972f71204c5ea42679881553454b6',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyRelationshipThreadView.vue',
    'e18a433d589336d52998444eaff9cc0bc202d30f0ce2a1cb14bb2db88dedf5b8',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyStatusPill.vue',
    'dd0f436ecba490fe549fcdec92b960fdbfb20c84e0e5fc515d426b9710771f1c',
  ],
  [
    'apps/main/src/modules/agency/components/AgencyUnifiedInbox.vue',
    'f244c9c9eb5759f3568c3c43810600e7681c73b36c0d41bc2e84e3bc0207adc3',
  ],
  [
    'apps/main/src/modules/agency/components/InboxFilterPopover.vue',
    '9a74b2088bbf923d36c0c4320aa8dcbc2779b81a56d831ebb43445db63f4b3f9',
  ],
  [
    'apps/main/src/modules/agency/components/MessageSearchResults.vue',
    'd6e7df782d2be9caef27943e06b213c7849d2fb46f07b7ce3f5412f999eb599f',
  ],
  [
    'apps/main/src/modules/agency/components/ThreadActionsMenu.vue',
    '8838c4221dc76f865ec0a6fa57d0bb144ff8ca0030397fcbe8a47ad9fbece2f2',
  ],
  [
    'apps/main/src/modules/agency/composables/brandColor.ts',
    'c172f6b3414befe0a8b4beb8b9ae5b762d27267c5a1e76f588164c422ce3115c',
  ],
  [
    'apps/main/src/modules/agency/composables/useMessageableCreatorIds.ts',
    '748ce87f761153da9a9008202f710538874f55c188aa9e6d5e5f12533bcf4732',
  ],
  [
    'apps/main/src/modules/agency/layouts/AgencyLayout.vue',
    '8f68dfea9a20b0a952c3d527987bda7007ff9e5d99f891700121c50481819af0',
  ],
  [
    'apps/main/src/modules/agency/status/agencyBrandTone.ts',
    '83ff22649ee19121d9fe230b2c1c24ff9d5cc3566d110591fe17c94425173aea',
  ],
  [
    'apps/main/src/modules/agency/status/agencyStatusTones.ts',
    'abeeb6768a8d51b4f50143ad2aac168b512218e6a63cba431250b6c26c5ee121',
  ],
  [
    'apps/main/src/modules/agency/status/draftRoundTone.ts',
    'fb580da7d46959cb67d55003c1d0ab0cd62a149fb9e76fd0aa05ad142d65ebf5',
  ],
  [
    'apps/main/src/modules/agency/theme/agency.css',
    '0d9bdc6837bdefb0f520426c972af27ed6022940fd38d83e7cdccab837fcc88c',
  ],
  [
    'apps/main/src/modules/agency/theme/agencyThemes.ts',
    '4d704b07b8837f2ea410676fdfab32a94dc57a5ee9ad5dd00a06858779ee021d',
  ],
  [
    'apps/main/src/modules/agency/theme/useAgencyTheme.ts',
    '1ca3e46a0d9e46ee6eeec861449d75f6cbfed830d3c8acea83dd04eb473ac741',
  ],
]

describe('agency-frozen (AH-115 L0.2)', () => {
  it('pins exactly the non-spec files of the agency module', () => {
    expect(listAgencyFiles(AGENCY_DIR)).toEqual(FROZEN_AGENCY_FILES.map(([file]) => file))
  })

  it.each(FROZEN_AGENCY_FILES)('%s is unchanged', (file, hash) => {
    expect(sha256(file)).toBe(hash)
  })
})
