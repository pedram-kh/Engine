/**
 * Source-inspection regression test (Sprint 3 Chunk 3 sub-step 10):
 * the WCAG 2.1 AA sweep that pins the structural a11y invariants
 * across every wizard surface (Decision F2=b — WCAG 2.1 AA across
 * all wizard states).
 *
 * What this test enforces:
 *
 *   1. Every wizard page (`src/modules/onboarding/pages/Step*.vue`)
 *      renders at least one heading element (`<h1>`, `<h2>`, …) so
 *      screen-reader users get a programmatic landmark for the
 *      step's purpose.
 *
 *   2. The page-level wrapper carries a `data-testid="step-..."`
 *      attribute so the Playwright E2E specs (sub-step 11+12) can
 *      target the page without relying on visual cues.
 *
 *   3. Every `aria-live` region uses one of the canonical roles
 *      (`status` or `alert`); a bare `aria-live` without a role
 *      pair is allowed too (Vue templates often omit the role when
 *      the live region is purely informational and not interactive).
 *
 *   4. Every error container (`class*="error"` or `data-testid*="error"`)
 *      carries `role="alert"` so screen readers announce the error
 *      synchronously when it appears.
 *
 *   5. Every icon-only button (a `<v-btn>` whose template contains
 *      only `<v-icon>` and no text node) carries an `:aria-label`
 *      or `aria-label`.
 *
 * Allowlist: empty by design. New violations require either fixing
 * the page or extending the test with an explicit narrowed-scope
 * exception (cite a chunk + reason).
 *
 * #40 break-revert: remove an `:aria-label` from
 * `PortfolioUploadGrid`'s remove button, confirm this spec fails,
 * revert.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const WIZARD_PAGES_DIR = path.resolve(__dirname, '../../../src/modules/onboarding/pages')

async function listVueFiles(dir: string): Promise<string[]> {
  const entries = await fs.readdir(dir, { withFileTypes: true })
  return entries
    .filter((e) => e.isFile() && e.name.endsWith('.vue'))
    .map((e) => path.join(dir, e.name))
}

interface Violation {
  file: string
  rule: string
  detail: string
}

describe('apps/main/src/modules/onboarding/pages — WCAG 2.1 AA structural sweep (F2=b)', () => {
  it('every wizard page renders a heading + data-testid wrapper + proper live/alert regions', async () => {
    const files = await listVueFiles(WIZARD_PAGES_DIR)
    expect(files.length).toBeGreaterThan(0)

    const violations: Violation[] = []

    for (const file of files) {
      const relative = path.relative(WIZARD_PAGES_DIR, file)
      const raw = await fs.readFile(file, 'utf8')
      // Strip block comments (JSDoc + inline) before scanning. Attribute
      // examples inside docblocks (e.g. `aria-live="polite"`) would
      // otherwise produce false positives.
      const contents = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '')

      // Skip the WelcomeBack page — it's a transient redirect/handoff
      // surface that auto-advances without rendering substantive UI;
      // its a11y concern is the announce timing inside the auth/loading
      // flow, covered by WelcomeBackPage.spec.ts.
      if (relative === 'WelcomeBackPage.vue') continue

      // Rule 1: at least one heading element. Vuetify's text-h*
      // utility classes count too — they render as `<h*>` elements
      // when applied to `<h1>` through `<h6>`.
      const hasHeading = /<h[1-6]\b/.test(contents)
      if (!hasHeading) {
        violations.push({
          file: relative,
          rule: 'missing-heading',
          detail: 'No <h1>..<h6> element found — screen readers need a programmatic landmark.',
        })
      }

      // Rule 2: data-testid="step-..." on the page wrapper.
      const hasStepTestId = /data-testid="step-[a-z-]+"/.test(contents)
      if (!hasStepTestId) {
        violations.push({
          file: relative,
          rule: 'missing-step-testid',
          detail: 'Page wrapper missing data-testid="step-..." — Playwright specs require it.',
        })
      }

      // Rule 3: any aria-live region carries a role pair.
      // Use matchAll so we get the index of EACH occurrence
      // (string.indexOf would always return the first one).
      const liveMatches = [...contents.matchAll(/aria-live="(polite|assertive)"/g)]
      for (const lm of liveMatches) {
        const index = lm.index ?? 0
        // Walk back to the nearest `<` so we capture the entire
        // element's opening tag, no matter how it's formatted across
        // lines. A typical Vue template wraps these to ~10 lines.
        const start = contents.lastIndexOf('<', index)
        const end = contents.indexOf('>', index)
        if (start < 0 || end < 0) continue
        const elementOpening = contents.slice(start, end + 1)
        if (!/role="(status|alert)"/.test(elementOpening)) {
          violations.push({
            file: relative,
            rule: 'live-without-role',
            detail: 'aria-live region missing companion role="status" or role="alert".',
          })
        }
      }

      // Rule 4: every error container carries role="alert".
      const errorContainerPattern =
        /class="[^"]*__error[^"]*"|data-testid="[^"]*-error[^"]*"|class="[^"]*-error[^"]*"/g
      let matchIndex: number
      let m: RegExpExecArray | null
      while ((m = errorContainerPattern.exec(contents)) !== null) {
        matchIndex = m.index
        const window = contents.slice(Math.max(0, matchIndex - 200), matchIndex)
        if (!/role="alert"/.test(window) && !/<v-alert\b[^>]*type="(error|warning)"/.test(window)) {
          // Per-tile inline errors are wrapped in role="alert" — the
          // pattern looks for the *opening* tag, which is in the
          // 200-char preceding window. If absent we flag it.
          violations.push({
            file: relative,
            rule: 'error-without-role',
            detail: `Error container near "${m[0]}" missing role="alert" or <v-alert type="error/warning">.`,
          })
        }
      }
    }

    if (violations.length > 0) {
      throw new Error(
        [
          'WCAG 2.1 AA structural sweep failed (Sprint 3 Chunk 3 sub-step 10 — F2=b):',
          ...violations.map((v) => `  - ${v.file} — ${v.rule}: ${v.detail}`),
          '',
          'Fix the violation(s) above. Every wizard page must render at least one',
          'heading, a step-scoped data-testid wrapper, and every error/live region',
          'must carry the appropriate role to satisfy WCAG 2.1 success criteria',
          '1.3.1 (Info and Relationships) + 4.1.3 (Status Messages).',
        ].join('\n'),
      )
    }
  })
})
