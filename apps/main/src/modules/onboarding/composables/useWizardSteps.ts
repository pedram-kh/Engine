/**
 * useWizardSteps — the SINGLE frontend source of truth for the wizard's
 * visible step list, its ordering, numbering, and per-step status.
 *
 * Why this exists (ad-hoc AH-003): the wizard chrome, the mobile progress
 * rail, the layout, and the review page previously each hard-coded the
 * 9-step list, a `TOTAL_STEPS = 9` constant, index maps, and even a `/7`
 * animation-geometry divisor — and a stale comment claimed the list was
 * rendered dynamically when it was not. All of that is now DERIVED from
 * {@link VISIBLE_UX_STEPS}, so:
 *
 *   - Reversible-hide is a one-line flip: shrink the backend
 *     `WizardStep::WIZARD_HIDDEN_STEPS` (mirrored by the shared
 *     `WIZARD_HIDDEN_STEPS` registry) and the step reappears in the rail,
 *     numbering, geometry, review rows, and submit gate with no other edit.
 *   - The numbering ("Step X of N"), the "01…0N" labels, and the animated
 *     chrome's layout maths all read `VISIBLE_UX_STEPS.length` rather than
 *     a magic number.
 *
 * UX vs backend steps:
 *   - The backend keeps `social` and `portfolio` as distinct completion
 *     units (their APIs + weights are unchanged). The SPA MERGES them into
 *     a single "connections" UX step with two sub-sections (D2). A merged
 *     step is complete only when every one of its (visible) backend steps
 *     is complete.
 *   - `kyc` / `tax` / `payout` are build-time hidden, so their UX steps
 *     drop out of {@link VISIBLE_UX_STEPS} entirely.
 */

import { isWizardStepHidden } from '@catalyst/api-client'
import type { CreatorWizardFlags, CreatorWizardStepId } from '@catalyst/api-client'

import { resolveStepStatus, type WizardStepStatus } from './useFeatureFlags'

export interface WizardUxStep {
  /**
   * Stable UX id. Also the i18n key segment under
   * `creator.ui.wizard.steps.<id>.name`.
   */
  id: string
  kind: 'account' | 'single' | 'merged'
  /**
   * The backend step ids this UX step covers. Empty for the static
   * account row; a single id for a normal step; two for the merged
   * "connections" step (`social` + `portfolio`).
   */
  stepIds: CreatorWizardStepId[]
  /** Router target for navigation; null = non-navigable (account row). */
  routeName: string | null
}

/**
 * The full UX step model BEFORE the build-time hidden filter. Social +
 * portfolio are already collapsed into the merged "connections" step here
 * (they are never separate UX steps post-AH-003).
 */
const BASE_UX_STEPS: readonly WizardUxStep[] = [
  { id: 'account_created', kind: 'account', stepIds: [], routeName: null },
  { id: 'profile', kind: 'single', stepIds: ['profile'], routeName: 'onboarding.profile' },
  {
    id: 'connections',
    kind: 'merged',
    stepIds: ['social', 'portfolio'],
    routeName: 'onboarding.connections',
  },
  { id: 'kyc', kind: 'single', stepIds: ['kyc'], routeName: 'onboarding.kyc' },
  { id: 'tax', kind: 'single', stepIds: ['tax'], routeName: 'onboarding.tax' },
  { id: 'payout', kind: 'single', stepIds: ['payout'], routeName: 'onboarding.payout' },
  { id: 'contract', kind: 'single', stepIds: ['contract'], routeName: 'onboarding.contract' },
  { id: 'review', kind: 'single', stepIds: ['review'], routeName: 'onboarding.review' },
]

/**
 * The visible UX steps: the account row plus every step with at least one
 * non-hidden backend step. With kyc/tax/payout hidden this is
 * `account → profile → connections → contract → review` (5 steps).
 */
export const VISIBLE_UX_STEPS: readonly WizardUxStep[] = BASE_UX_STEPS.filter(
  (step) => step.kind === 'account' || step.stepIds.some((id) => !isWizardStepHidden(id)),
)

/**
 * Total visible steps including the static account row and the review
 * submit action. Drives every "Step X of N" caption — never hard-code 9.
 */
export const WIZARD_TOTAL_STEPS = VISIBLE_UX_STEPS.length

/**
 * The substantive review rows: visible UX steps minus the account row and
 * minus review itself (review is the submit surface, not a row).
 */
export const REVIEW_UX_STEPS: readonly WizardUxStep[] = VISIBLE_UX_STEPS.filter(
  (step) => step.kind !== 'account' && step.id !== 'review',
)

/** The i18n key for a UX step's display name. */
export function uxStepTitleKey(step: WizardUxStep): string {
  return `creator.ui.wizard.steps.${step.id}.name`
}

/**
 * Whether a UX step counts as complete. A merged step is complete only
 * when every one of its (visible) backend steps is complete; the account
 * row is always complete; a single step reads its backend flag.
 */
export function resolveUxStepComplete(
  step: WizardUxStep,
  stepCompletion: Record<CreatorWizardStepId, boolean>,
): boolean {
  if (step.kind === 'account') return true
  const visibleSubSteps = step.stepIds.filter((id) => !isWizardStepHidden(id))
  return visibleSubSteps.every((id) => stepCompletion[id] ?? false)
}

/**
 * Resolve the visible status of a UX step. Single steps defer to
 * {@link resolveStepStatus} so a flag-OFF step still renders "skipped";
 * merged steps have no flag-gated sub-steps, so they are simply
 * completed / not-started; the account row is always completed.
 */
export function resolveUxStepStatus(
  step: WizardUxStep,
  stepCompletion: Record<CreatorWizardStepId, boolean>,
  flags: CreatorWizardFlags | null,
): WizardStepStatus {
  if (step.kind === 'account') return 'completed'
  if (step.kind === 'merged') {
    return resolveUxStepComplete(step, stepCompletion) ? 'completed' : 'not-started'
  }
  const stepId = step.stepIds[0]
  if (stepId === undefined) return 'not-started'
  return resolveStepStatus(stepId, stepCompletion[stepId] ?? false, flags)
}

/** Index of the visible UX step served by a route name, or -1. */
export function uxIndexForRoute(routeName: string): number {
  return VISIBLE_UX_STEPS.findIndex((step) => step.routeName === routeName)
}

/**
 * Index of the visible UX step that owns a backend step id (maps
 * `social` / `portfolio` onto the merged "connections" step). Falls back
 * to the first navigable step when the id is hidden / unknown.
 */
export function uxIndexForBackendStep(stepId: CreatorWizardStepId): number {
  const idx = VISIBLE_UX_STEPS.findIndex((step) => step.stepIds.includes(stepId))
  return idx === -1 ? 1 : idx
}

/** The route name a backend step id should navigate to, or null. */
export function routeForBackendStep(stepId: CreatorWizardStepId): string | null {
  const idx = uxIndexForBackendStep(stepId)
  return VISIBLE_UX_STEPS[idx]?.routeName ?? null
}
