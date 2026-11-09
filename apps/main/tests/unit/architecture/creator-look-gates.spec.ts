/**
 * AH-115 L3 — the creator pages draw the native look from `useNativeLook()` (the Capacitor app OR
 * the creator web shell), not from the platform probe. The probe stays only where a gate is
 * behaviour (the upload prompts name the gesture: "Tap" on a phone app, never on the web).
 *
 * Auth screens are NOT in this list on purpose (ruling 1: tokens and components only, hero and
 * tail kept): SignInPage, SignUpPage, EmailVerificationPendingPage and AuthHeroPanel keep
 * `isNativePlatform()`.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src/modules')
const read = (file: string): string => readFileSync(path.join(SRC, file), 'utf8')

const LOOK_ONLY = [
  'creators/pages/CreatorDashboardPage.vue',
  'creators/pages/CreatorAssignmentsPage.vue',
  'creators/pages/CreatorAssignmentDetailPage.vue',
  'creators/pages/CreatorJobDetailPage.vue',
  'creators/pages/SecurityPage.vue',
  'creators/components/DeleteAccountDialog.vue',
  'creators/availability/pages/AvailabilityPage.vue',
  'creators/availability/components/AvailabilityBlockDialog.vue',
  'onboarding/pages/WelcomeBackPage.vue',
  'onboarding/components/ConnectionsPortfolioSection.vue',
  'onboarding/components/ConnectionsSocialSection.vue',
]

const AUTH_KEEPS_PROBE = [
  'auth/pages/SignInPage.vue',
  'auth/pages/SignUpPage.vue',
  'auth/pages/EmailVerificationPendingPage.vue',
  'auth/components/AuthHeroPanel.vue',
]

describe('creator look gates (AH-115 L3)', () => {
  it.each(LOOK_ONLY)('%s reads useNativeLook and not the platform probe', (file) => {
    const source = read(file)
    expect(source).toContain("from '@/composables/useNativeLook'")
    expect(source).not.toContain('isNativePlatform')
  })

  it.each([
    'onboarding/components/AvatarUploadDrop.vue',
    'onboarding/components/PortfolioUploadGrid.vue',
  ])('%s: look from useNativeLook, the gesture word from the platform', (file) => {
    const source = read(file)
    expect(source).toContain('useNativeLook')
    expect(source).toContain('isNativePlatform')
    expect(source).toMatch(/_prompt_native/)
    expect(source).toMatch(/_prompt_web|_prompt_drop_web/)
  })

  it.each(AUTH_KEEPS_PROBE)('%s keeps isNativePlatform (auth is tokens only, ruling 1)', (file) => {
    const source = read(file)
    expect(source).toContain('isNativePlatform')
    expect(source).not.toContain('useNativeLook')
  })
})
