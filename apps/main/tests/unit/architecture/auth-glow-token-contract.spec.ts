/**
 * Source-inspection regression test — the `--auth-glow-gradient` contract.
 *
 * AH-063 changed what this token MEANS, not just its value. It used to
 * carry its strength baked into the stops (`rgba(…, 0.2)`); it is now the
 * aurora at FULL strength, and every consumer is responsible for dimming
 * it itself with `opacity`. The footer's radial bloom needs the
 * undimmed gradient to mask, which is why the alpha had to come out of
 * the token.
 *
 * That makes the contract two-sided and unenforced by the type system:
 *
 *   1. The token is full-strength — no alpha in its definition.
 *   2. Every consumer declares `opacity` in the SAME rule that consumes
 *      it.
 *
 * A consumer that forgets (2) renders the aurora at roughly five times
 * the intended strength. Nothing else catches it: `aurora-surfacing.spec.ts`
 * only checks the var is REFERENCED, `no-hard-coded-colors.spec.ts` only
 * forbids hex literals in SFCs, and the design-tokens spec does not cover
 * any `--auth-*` token. At the time of writing there were three
 * consumers across two SPAs and all three were correct — but that was
 * confirmed by hand at batch close-out, which is exactly the kind of
 * check that stops happening.
 *
 * Consumers are DISCOVERED by scanning both SPAs rather than listed, so a
 * fourth one is covered the moment it is written instead of when someone
 * remembers to add it here.
 *
 * Break-revert: delete the `opacity: 0.3` line from any consumer (e.g.
 * `apps/admin/src/modules/auth/layouts/AuthLayout.vue`) → the "declares
 * opacity in the same rule" assertion fails and names the file.
 */

import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')

const TOKEN = '--auth-glow-gradient'
const TOKEN_REF = `var(${TOKEN})`
const TOKENS_FILE = 'packages/design-tokens/tokens.css'

/** Both SPAs consume the token; both must honour the contract. */
const SPA_SOURCE_ROOTS: ReadonlyArray<string> = ['apps/main/src', 'apps/admin/src']

const STYLE_EXTENSIONS = ['.vue', '.css']

function styleFilesUnder(absoluteDir: string): string[] {
  return readdirSync(absoluteDir, { withFileTypes: true }).flatMap((entry) => {
    const child = path.join(absoluteDir, entry.name)
    if (entry.isDirectory()) {
      return styleFilesUnder(child)
    }
    return STYLE_EXTENSIONS.includes(path.extname(entry.name)) ? [child] : []
  })
}

/** Comments mentioning the token are prose, not consumption. */
function withoutCssComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '')
}

/**
 * The innermost `{ … }` block containing `index`.
 *
 * Walks back to the opening brace at depth zero, then forward to its
 * match, so a rule nested inside a media query yields the rule and not
 * the whole at-rule.
 */
function enclosingRule(source: string, index: number): string {
  let depth = 0
  let start = -1

  for (let i = index; i >= 0; i -= 1) {
    const character = source[i]
    if (character === '}') {
      depth += 1
    } else if (character === '{') {
      if (depth === 0) {
        start = i
        break
      }
      depth -= 1
    }
  }

  if (start === -1) {
    return ''
  }

  let open = 0
  for (let i = start; i < source.length; i += 1) {
    const character = source[i]
    if (character === '{') {
      open += 1
    } else if (character === '}') {
      open -= 1
      if (open === 0) {
        return source.slice(start, i + 1)
      }
    }
  }

  return source.slice(start)
}

interface Consumption {
  readonly file: string
  readonly rule: string
}

function findConsumptions(): Consumption[] {
  const found: Consumption[] = []

  for (const root of SPA_SOURCE_ROOTS) {
    for (const absolute of styleFilesUnder(path.resolve(REPO_ROOT, root))) {
      const source = withoutCssComments(readFileSync(absolute, 'utf8'))
      let from = source.indexOf(TOKEN_REF)
      while (from !== -1) {
        found.push({
          file: path.relative(REPO_ROOT, absolute),
          rule: enclosingRule(source, from),
        })
        from = source.indexOf(TOKEN_REF, from + TOKEN_REF.length)
      }
    }
  }

  return found
}

const consumptions = findConsumptions()

describe(`${TOKEN} is full-strength and every consumer dims it`, () => {
  it('is consumed somewhere — a rename must not empty this suite silently', () => {
    // Without this, renaming the token turns every assertion below into a
    // vacuous pass over an empty list.
    expect(consumptions.length).toBeGreaterThan(0)
  })

  it('is defined at full strength, with no alpha baked into the stops', () => {
    const tokens = withoutCssComments(readFileSync(path.resolve(REPO_ROOT, TOKENS_FILE), 'utf8'))
    const definition = tokens.split('\n').find((line) => line.includes(`${TOKEN}:`))

    expect(definition, `${TOKEN} is not defined in ${TOKENS_FILE}`).toBeDefined()
    // `rgba(` here would mean the token dims itself again, and every
    // consumer's own `opacity` would then compound it.
    expect(definition?.toLowerCase()).not.toContain('rgba(')
  })

  it('is dimmed by an opacity declaration in the same rule, at every consumer', () => {
    const undimmed = consumptions
      .filter(({ rule }) => !/\bopacity\s*:/.test(rule))
      .map(({ file }) => file)

    expect(
      undimmed,
      `${TOKEN} is full-strength; these consumers use it without an opacity in the same rule, so the aurora renders ~5x too bright: ${undimmed.join(', ')}`,
    ).toEqual([])
  })
})
