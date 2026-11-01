/**
 * AH-114 spec 6 — `agency-status-tone`.
 *
 * Figma rule R9: a status is a pill, never a bare coloured chip, and its colour and its
 * word come from one table (`AGENCY_STATUS_TONES`). This spec is what keeps a new
 * backend status from shipping as an uncoloured or unlabelled chip:
 *
 *   1. COVERAGE — for every status union the agency app renders, the string literals
 *      of the union (read from the source text in `packages/api-client`, so a scratch
 *      edit to the union fails THIS spec, not only the type check) are exactly the
 *      keys of its table. A value missing from the table fails; a table key that is
 *      not in the union (a stale value) fails too.
 *   2. TONE — every entry has one of the six tones, or `null` (no pill) with no label.
 *   3. LABEL — every entry that shows a pill names an i18n key that resolves to a
 *      non-empty string in ALL 24 UI locales (the key already exists today; none is
 *      added by this chunk).
 *   4. SCOPE — the unions deliberately left out are named here, so leaving one out is a
 *      decision someone made, not an omission.
 *
 * Break-revert: add `'archived_x'` to `BrandStatus` in `packages/api-client/src/types/
 * agency.ts` → check 1 fails → revert.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

import {
  AGENCY_STATUS_SOURCES,
  AGENCY_STATUS_TONES,
  type AgencyStatusEntry,
  type AgencyStatusKind,
} from '@/modules/agency/status/agencyStatusTones'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')
const API_TYPES = path.join(REPO_ROOT, 'packages/api-client/src')
const LOCALES = path.join(REPO_ROOT, 'apps/main/src/core/i18n/locales')

const TONES = ['success', 'info', 'warning', 'danger', 'neutral', 'ai'] as const

/** Status unions the agency app does NOT render as a pill, and why. */
const OUT_OF_SCOPE = [
  { type: 'JobLifecycleState', why: 'creator-facing (inventory §6.2)' },
  { type: 'boardStatus', why: 'board columns keep their own palette (plan ruling 7)' },
] as const

/** The members of `export type <name> = 'a' | 'b' …`, single- or multi-line, comments ignored. */
function unionMembers(file: string, name: string): string[] {
  const lines = readFileSync(path.join(API_TYPES, file), 'utf8').split('\n')
  const start = lines.findIndex((line) => new RegExp(`^export type ${name}\\b`).test(line))
  if (start === -1) throw new Error(`export type ${name} not found in ${file}`)

  const body: string[] = [(lines[start] as string).replace(/^[^=]*=/, '')]
  for (let i = start + 1; i < lines.length; i++) {
    const line = lines[i] as string
    if (!/^\s/.test(line)) break // the union continues only on indented lines
    body.push(line)
  }
  const code = body.map((line) => line.replace(/\/\/.*$/, '')).join('\n')
  return [...code.matchAll(/'([^']+)'/g)].map((match) => match[1] as string)
}

function lookup(tree: unknown, dotted: string): unknown {
  return dotted
    .split('.')
    .reduce<unknown>(
      (node, part) =>
        node !== null && typeof node === 'object'
          ? (node as Record<string, unknown>)[part]
          : undefined,
      tree,
    )
}

const kinds = Object.keys(AGENCY_STATUS_SOURCES) as AgencyStatusKind[]

describe('agency-status-tone (spec 6)', () => {
  it('has a table for every kind it declares a source for, and no other', () => {
    expect(Object.keys(AGENCY_STATUS_TONES).sort()).toEqual([...kinds].sort())
  })

  describe.each(kinds)('%s', (kind) => {
    const { type, file } = AGENCY_STATUS_SOURCES[kind]
    const table = AGENCY_STATUS_TONES[kind] as Readonly<Record<string, AgencyStatusEntry>>

    it(`covers every value of ${type}, and only those`, () => {
      const members = unionMembers(file, type)
      expect(members.length).toBeGreaterThan(0)
      expect(Object.keys(table).sort()).toEqual([...new Set(members)].sort())
    })

    it('gives every value one of the six tones, or no pill and no label', () => {
      for (const [value, entry] of Object.entries(table)) {
        if (entry.tone === null) {
          expect(entry.labelKey, `${kind}.${value}: no pill means no label`).toBeNull()
        } else {
          expect(TONES, `${kind}.${value}`).toContain(entry.tone)
          expect(entry.labelKey, `${kind}.${value} has a tone but no label key`).toEqual(
            expect.any(String),
          )
        }
      }
    })

    it('resolves every label key to a non-empty word in all 24 locales', () => {
      expect(UI_LOCALES).toHaveLength(24)
      for (const locale of UI_LOCALES) {
        const app = JSON.parse(
          readFileSync(path.join(LOCALES, locale, 'app.json'), 'utf8'),
        ) as unknown
        for (const [value, entry] of Object.entries(table)) {
          if (entry.labelKey === null) continue
          // `app.json` nests everything under a top-level `app` object, so the key
          // is looked up as written.
          const word = lookup(app, entry.labelKey)
          expect(word, `${locale}: ${entry.labelKey} (${kind}.${value})`).toEqual(
            expect.any(String),
          )
          expect((word as string).trim(), `${locale}: ${entry.labelKey} is empty`).not.toBe('')
        }
      }
    })
  })

  it('names what it leaves out', () => {
    expect(OUT_OF_SCOPE.map((entry) => entry.type)).toEqual(['JobLifecycleState', 'boardStatus'])
    const inScope = new Set(kinds.map((kind) => AGENCY_STATUS_SOURCES[kind].type))
    for (const { type } of OUT_OF_SCOPE) {
      expect(inScope.has(type), `${type} is declared out of scope but has a table`).toBe(false)
    }
  })
})
