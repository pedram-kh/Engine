/**
 * Source-inspection architecture gate — the AH-096 class-killer.
 *
 * AH-096 found `roster.api.ts`'s `list()` accepting `RosterListParams` with
 * `age_min`/`age_max` on the type (added AH-088) but never reading either
 * field in the query-string builder — a silent no-op filter. The page called
 * the wrapper correctly; the wrapper dropped two keys on the floor. No
 * existing test caught it because `CreatorRosterPage.spec.ts` mocks
 * `roster.api.ts` and only asserts the PAGE passed the right params object —
 * proving nothing about what actually reaches the wire (the mock-boundary
 * lesson named in the AH-096 log entry).
 *
 * This test harvests every `export interface *Params { ... }` (in
 * `@catalyst/api-client`'s shared types AND locally inside each `*.api.ts`
 * wrapper file), finds every method signature of the shape
 * `params: SomeParams` inside a `*.api.ts` file, isolates that method's own
 * body, and asserts every field declared on the type is referenced as
 * `params.<field>` somewhere inside it. A field declared but never
 * referenced is exactly the roster bug's signature, wherever it next occurs.
 *
 * Known limitation (same honesty convention as
 * `i18n-used-keys-resolve.spec.ts`'s "third shape"): a params object typed
 * inline (e.g. `params: { page?: number } = {}`, no named interface) is not
 * harvested — there is nothing to check a bare inline type against without
 * re-deriving it from itself. None of the wrappers audited at AH-096 use
 * that shape for a filter-bearing list method (only `talentPools.api.ts`'s
 * `members()`, page/per_page only, both threaded); if one ever does, this
 * test silently does not cover it — extend the harvest if that changes.
 */

import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')
const API_CLIENT_TYPES_ROOT = path.resolve(
  __dirname,
  '../../../../../packages/api-client/src/types',
)

interface ParamsInterface {
  name: string
  fields: string[]
}

interface Gap {
  file: string
  typeName: string
  missingFields: string[]
}

/** Strips `/** ... *​/` blocks and whole comment-only lines, never a mid-line trailing `//`
 * (a `params:`-adjacent string could legitimately contain `//`, e.g. a URL). */
function stripComments(source: string): string {
  const noBlockComments = source.replace(/\/\*\*[\s\S]*?\*\//g, '')
  return noBlockComments
    .split('\n')
    .map((line) => (line.trim().startsWith('//') ? '' : line))
    .join('\n')
}

function findMatchingBrace(text: string, openBraceIndex: number): number {
  let depth = 1
  for (let i = openBraceIndex + 1; i < text.length; i++) {
    if (text[i] === '{') depth++
    else if (text[i] === '}') {
      depth--
      if (depth === 0) return i
    }
  }
  throw new Error(`Unbalanced braces scanning from index ${openBraceIndex}`)
}

const INTERFACE_HEADER = /export interface (\w*Params)\s*\{/g

function extractInterfaces(contents: string): ParamsInterface[] {
  const stripped = stripComments(contents)
  const found: ParamsInterface[] = []
  for (const match of stripped.matchAll(INTERFACE_HEADER)) {
    const name = match[1]
    if (name === undefined) continue
    const openBraceIndex = match.index! + match[0].length - 1
    const closeBraceIndex = findMatchingBrace(stripped, openBraceIndex)
    const body = stripped.slice(openBraceIndex + 1, closeBraceIndex)
    const fields = [...body.matchAll(/^\s*(\w+)\??\s*:/gm)].map((m) => m[1]!)
    found.push({ name, fields })
  }
  return found
}

function walkFiles(directory: string, predicate: (name: string) => boolean, out: string[] = []) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) walkFiles(absolute, predicate, out)
    else if (predicate(entry.name)) out.push(absolute)
  }
  return out
}

/** Every declared `*Params` interface, shared api-client types first, then
 * each wrapper's own local declarations (both populate one flat map — a
 * wrapper never shadows a shared name with a different meaning in this
 * codebase, and if it ever did, whichever is scanned last wins, which is
 * visible in a failing diff rather than silent). */
function buildInterfaceMap(): Map<string, ParamsInterface> {
  const map = new Map<string, ParamsInterface>()
  const sharedTypeFiles = readdirSync(API_CLIENT_TYPES_ROOT).filter((f) => f.endsWith('.ts'))
  for (const file of sharedTypeFiles) {
    for (const iface of extractInterfaces(
      readFileSync(path.join(API_CLIENT_TYPES_ROOT, file), 'utf8'),
    )) {
      map.set(iface.name, iface)
    }
  }
  for (const file of walkFiles(SRC_ROOT, (name) => name.endsWith('.api.ts'))) {
    for (const iface of extractInterfaces(readFileSync(file, 'utf8'))) {
      map.set(iface.name, iface)
    }
  }
  return map
}

const PARAM_USAGE = /\bparams:\s*(\w+)\b/g

/** A method may factor its query-building into a same-file helper instead of
 * inlining it (`members.api.ts`'s `buildQuery(params)`, called from `list()`).
 * Matches the delegate call so the helper's OWN body — not the thin method
 * that just forwards to it — is where fields are actually checked. */
const DELEGATE_CALL = /\b(\w+)\(\s*params\b/

function findNamedFunctionBody(fileText: string, name: string): string | null {
  const signature = new RegExp(`function\\s+${name}\\s*\\(`)
  const match = signature.exec(fileText)
  if (match === null) return null
  const body = locateMethodBody(fileText, match.index + match[0].length)
  return body === null ? null : fileText.slice(body.start, body.end)
}

/** From just past a `params: TypeName` match (already inside the method's
 * own open paren), scans forward to that paren's matching close, then to the
 * next `{` (the function body — every params-typed method audited at AH-096
 * has a plain `Promise<X>` return type with no inline-object generic, so the
 * first `{` after the closing paren is reliably the body, not part of the
 * return type). */
function locateMethodBody(
  text: string,
  afterParamsMatch: number,
): { start: number; end: number } | null {
  let depth = 1
  let i = afterParamsMatch
  for (; i < text.length && depth > 0; i++) {
    if (text[i] === '(') depth++
    else if (text[i] === ')') depth--
  }
  if (depth !== 0) return null
  const closeParenIndex = i - 1
  const braceIndex = text.indexOf('{', closeParenIndex)
  if (braceIndex === -1) return null
  const end = findMatchingBrace(text, braceIndex)
  return { start: braceIndex, end }
}

function findGaps(): Gap[] {
  const interfaceMap = buildInterfaceMap()
  const gaps: Gap[] = []
  for (const file of walkFiles(SRC_ROOT, (name) => name.endsWith('.api.ts'))) {
    const relative = path.relative(SRC_ROOT, file)
    const stripped = stripComments(readFileSync(file, 'utf8'))
    for (const match of stripped.matchAll(PARAM_USAGE)) {
      const typeName = match[1]!
      const iface = interfaceMap.get(typeName)
      if (iface === undefined || iface.fields.length === 0) continue
      const body = locateMethodBody(stripped, match.index! + match[0].length)
      if (body === null) {
        gaps.push({
          file: relative,
          typeName,
          missingFields: [
            '<could not locate the method body — harness limitation, see file docblock>',
          ],
        })
        continue
      }
      let bodyText = stripped.slice(body.start, body.end)
      const delegateMatch = DELEGATE_CALL.exec(bodyText)
      if (delegateMatch?.[1] !== undefined) {
        const delegateBody = findNamedFunctionBody(stripped, delegateMatch[1])
        if (delegateBody !== null) bodyText += `\n${delegateBody}`
      }
      const missing = iface.fields.filter((f) => !new RegExp(`\\bparams\\.${f}\\b`).test(bodyText))
      if (missing.length > 0) {
        gaps.push({ file: relative, typeName, missingFields: missing })
      }
    }
  }
  return gaps
}

describe('main api wrappers — every *Params field a method accepts is actually serialized (AH-096)', () => {
  it('finds at least the known params-typed list methods (sanity check)', () => {
    const interfaceMap = buildInterfaceMap()
    expect(interfaceMap.has('RosterListParams')).toBe(true)
    expect(interfaceMap.get('RosterListParams')!.fields).toContain('age_min')
    expect(interfaceMap.get('RosterListParams')!.fields).toContain('age_max')
  })

  it('every *Params field a wrapper method accepts is referenced in its own body', () => {
    const gaps = findGaps()
    if (gaps.length > 0) {
      const lines = gaps.map(
        (g) => `  - ${g.typeName} (${g.file}): missing ${g.missingFields.join(', ')}`,
      )
      throw new Error(
        [
          `${gaps.length} wrapper method(s) accept a *Params field they never serialize:`,
          ...lines,
          '',
          'The page caller almost certainly sets this field (it exists on the type for a ' +
            'reason) — thread it into the query string, or drop it from the type if it is ' +
            'genuinely dead.',
        ].join('\n'),
      )
    }
  })
})
