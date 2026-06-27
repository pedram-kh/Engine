/**
 * Architecture test — wizard hidden-step registry + TS<->PHP parity
 * (standing standard 5.25).
 *
 * The frontend `WIZARD_HIDDEN_STEPS` registry in `wizard.ts` and the
 * backend `App\Modules\Creators\Enums\WizardStep::WIZARD_HIDDEN_STEPS`
 * constant are two copies of the same source of truth. This
 * source-inspection test parses the PHP constant (no eval) and asserts
 * the two lists match.
 *
 * If either drifts, the SPA could render/number/gate a step the backend
 * has dropped from `wizard.steps[]` and the submit gate (or vice versa) —
 * exactly the silent incoherence the shared registry guards against.
 */

import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

import { WIZARD_HIDDEN_STEPS, isWizardStepHidden } from './wizard'

const __dirname = dirname(fileURLToPath(import.meta.url))
// packages/api-client/src -> api-client -> packages -> repo root
const REPO_ROOT = join(__dirname, '..', '..', '..')
const BACKEND_ENUM_PATH = join(
  REPO_ROOT,
  'apps',
  'api',
  'app',
  'Modules',
  'Creators',
  'Enums',
  'WizardStep.php',
)

/** Extract the quoted strings of a `const array NAME = [...]` declaration. */
function parsePhpArrayConst(php: string, constName: string): string[] {
  const re = new RegExp(`const array ${constName} = \\[([\\s\\S]*?)\\];`, 'm')
  const match = re.exec(php)
  if (match === null) {
    throw new Error(`Could not find PHP const ${constName} in WizardStep.php`)
  }
  const items: string[] = []
  const itemRe = /'([^']+)'/g
  let m: RegExpExecArray | null
  while ((m = itemRe.exec(match[1] ?? '')) !== null) {
    if (m[1] !== undefined) items.push(m[1])
  }
  return items
}

describe('wizard hidden-step registry integrity', () => {
  it('WIZARD_HIDDEN_STEPS has no duplicates', () => {
    expect(new Set(WIZARD_HIDDEN_STEPS).size).toBe(WIZARD_HIDDEN_STEPS.length)
  })

  it('isWizardStepHidden agrees with the list', () => {
    expect(isWizardStepHidden('kyc')).toBe(true)
    expect(isWizardStepHidden('tax')).toBe(true)
    expect(isWizardStepHidden('payout')).toBe(true)
    expect(isWizardStepHidden('profile')).toBe(false)
    expect(isWizardStepHidden('social')).toBe(false)
    expect(isWizardStepHidden('portfolio')).toBe(false)
    expect(isWizardStepHidden('contract')).toBe(false)
    expect(isWizardStepHidden('review')).toBe(false)
  })
})

describe('TS <-> PHP wizard hidden-step parity (standing standard 5.25)', () => {
  const php = readFileSync(BACKEND_ENUM_PATH, 'utf-8')

  it('WizardStep::WIZARD_HIDDEN_STEPS matches the TS WIZARD_HIDDEN_STEPS', () => {
    const backend = parsePhpArrayConst(php, 'WIZARD_HIDDEN_STEPS')
    expect([...backend].sort()).toEqual([...WIZARD_HIDDEN_STEPS].sort())
  })
})
