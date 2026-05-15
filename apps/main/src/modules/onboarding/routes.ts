/**
 * Onboarding-module route table (Sprint 3 Chunk 3 sub-step 2).
 *
 * Every route in this module:
 *   - Lives under `OnboardingLayout.vue` (the wizard chrome).
 *   - Gates via `[requireAuth, requireOnboardingAccess]`. The second
 *     guard enforces user_type=creator + application_status=incomplete;
 *     submitted/approved/rejected creators redirect to
 *     `/creator/dashboard`.
 *   - Has no explicit `requireMfaEnrolled` — creators are not required
 *     to enrol MFA at onboarding time per `docs/feature-flags.md` /
 *     spec § 5.
 *
 * The wizard step pages themselves land in sub-steps 5-8; this
 * sub-step 2 commit registers the chrome + the Welcome Back surface
 * + lazy-loadable placeholders.
 */

import type { RouteRecordRaw } from 'vue-router'

export const onboardingRoutes: RouteRecordRaw[] = [
  {
    path: '/onboarding',
    name: 'onboarding.welcome-back',
    component: () => import('./pages/WelcomeBackPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
  {
    path: '/onboarding/profile',
    name: 'onboarding.profile',
    component: () => import('./pages/Step2ProfileBasicsPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
  {
    path: '/onboarding/social',
    name: 'onboarding.social',
    component: () => import('./pages/Step3SocialAccountsPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
  {
    path: '/onboarding/portfolio',
    name: 'onboarding.portfolio',
    component: () => import('./pages/Step4PortfolioPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
  {
    path: '/onboarding/kyc',
    name: 'onboarding.kyc',
    component: () => import('./pages/Step5KycPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
  {
    path: '/onboarding/tax',
    name: 'onboarding.tax',
    component: () => import('./pages/Step6TaxPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
  {
    path: '/onboarding/payout',
    name: 'onboarding.payout',
    component: () => import('./pages/Step7PayoutPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
  {
    path: '/onboarding/contract',
    name: 'onboarding.contract',
    component: () => import('./pages/Step8ContractPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
  {
    path: '/onboarding/review',
    name: 'onboarding.review',
    component: () => import('./pages/Step9ReviewPage.vue'),
    meta: {
      layout: 'onboarding',
      guards: ['requireAuth', 'requireOnboardingAccess'],
    },
  },
]

/**
 * Map a `CreatorWizardStepId` to the SPA route name it lands at.
 * Used by the Welcome Back page + the auto-advance logic + the
 * progress-indicator nav-links to translate the backend's wizard-step
 * identifiers into router navigation targets.
 */
export const WIZARD_STEP_ROUTE_NAMES = {
  profile: 'onboarding.profile',
  social: 'onboarding.social',
  portfolio: 'onboarding.portfolio',
  kyc: 'onboarding.kyc',
  tax: 'onboarding.tax',
  payout: 'onboarding.payout',
  contract: 'onboarding.contract',
  review: 'onboarding.review',
} as const
