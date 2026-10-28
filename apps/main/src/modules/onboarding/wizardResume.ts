import type { CreatorWizardStepId } from '@catalyst/api-client'

import { WIZARD_STEP_ROUTE_NAMES } from './routes'

/**
 * AH-104d (W8, 2026-10-28): where "back into the wizard" goes.
 *
 * The creator's CURRENT step is the backend's `wizard.next_step` (the first step not
 * yet complete), mapped to its route the same way the Welcome Back page's "Continue"
 * does. A brand-new creator (no `next_step` yet) starts at the profile step. The two
 * social/portfolio ids share one merged route, and `review` has its own.
 */
export function wizardCurrentStepLocation(nextStep: CreatorWizardStepId | null): {
  name: string
} {
  return { name: WIZARD_STEP_ROUTE_NAMES[nextStep ?? 'profile'] }
}

/** Whether a route name belongs to the onboarding wizard (`onboarding.*`). */
export function isWizardRouteName(name: unknown): boolean {
  return typeof name === 'string' && name.startsWith('onboarding.')
}
