/**
 * AH-114 spec 3 — `shared-tokens-frozen`.
 *
 * The agency restyle (AH-114) must not move ANYTHING the creator web, the
 * creator native app or the admin SPA read. This spec freezes that surface
 * on the unchanged tree (`4780b8a6`), BEFORE any agency theme code exists, so
 * every later step is checked against it.
 *
 *   - Literal copies of `lightTheme`, `darkTheme`, `nativeDarkTheme` and
 *     `nativePalette` (the agency themes are DERIVED from `nativePalette`,
 *     so a drift in it would silently restyle the agency).
 *   - sha256 of the token CSS, the token TS sources, the Inter font CSS,
 *     the web Vuetify plugin, `App.vue` and the two theme composables.
 *
 * A failure names the file. If the change is intentional (for example
 * AH-115, the creator restyle, which is the chunk that is MEANT to change
 * these), update the literal or the hash in the same commit and say why.
 * Hashes are over the file text with CRLF normalised to LF.
 */

import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { nativeDarkTheme, nativePalette } from '@catalyst/design-tokens/native'
import { darkTheme, lightTheme } from '@catalyst/design-tokens/vuetify'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')

function sha256(file: string): string {
  const text = readFileSync(path.join(REPO_ROOT, file), 'utf8').replace(/\r\n/g, '\n')
  return createHash('sha256').update(text).digest('hex')
}

const FROZEN_LIGHT = {
  dark: false,
  colors: {
    background: '#FAFAFA',
    'on-background': '#27272A',
    surface: '#FFFFFF',
    'surface-bright': '#FFFFFF',
    'surface-light': '#FFFFFF',
    'surface-variant': '#F4F4F5',
    'on-surface': '#27272A',
    'on-surface-variant': '#71717A',
    primary: '#14B8A6',
    'primary-darken-1': '#0F9488',
    'on-primary': '#FFFFFF',
    secondary: '#F4F4F5',
    'on-secondary': '#27272A',
    error: '#DC2626',
    'on-error': '#FFFFFF',
    info: '#0284C7',
    'on-info': '#FFFFFF',
    success: '#16A34A',
    'on-success': '#FFFFFF',
    warning: '#F59E0B',
    'on-warning': '#09090B',
    'border-color': '#D4D4D8',
    accent: '#8B5CF6',
    outline: '#D4D4D8',
    'outline-variant': '#E4E4E7',
    'primary-container': '#E6F8F5',
    'error-container': '#FEE2E2',
  },
}

const FROZEN_DARK = {
  dark: true,
  colors: {
    background: '#09090B',
    'on-background': '#D4D4D8',
    surface: '#18181B',
    'surface-bright': '#27272A',
    'surface-light': '#18181B',
    'surface-variant': '#09090B',
    'on-surface': '#D4D4D8',
    'on-surface-variant': '#A1A1AA',
    primary: '#2ECDAE',
    'primary-darken-1': '#14B8A6',
    'on-primary': '#09090B',
    secondary: '#27272A',
    'on-secondary': '#FAFAFA',
    error: '#DC2626',
    'on-error': '#FFFFFF',
    info: '#0284C7',
    'on-info': '#FFFFFF',
    success: '#16A34A',
    'on-success': '#FFFFFF',
    warning: '#F59E0B',
    'on-warning': '#09090B',
    'border-color': '#3F3F46',
    accent: '#9E63F6',
    outline: '#3F3F46',
    'outline-variant': '#27272A',
    'primary-container': '#074A44',
    'error-container': '#3B1A1A',
  },
}

const FROZEN_NATIVE_DARK = {
  dark: true,
  colors: {
    background: '#0b0b0c',
    'on-background': '#ededed',
    surface: '#1b1b1d',
    'surface-bright': '#232326',
    'surface-light': '#1b1b1d',
    'surface-variant': '#151516',
    'on-surface': '#ededed',
    'on-surface-variant': '#a0a0a8',
    primary: '#3ecf9e',
    'primary-darken-1': '#2fb487',
    'on-primary': '#0b0b0c',
    secondary: '#232326',
    'on-secondary': '#ededed',
    error: '#f0506e',
    'on-error': '#0b0b0c',
    info: '#4c8dff',
    'on-info': '#0b0b0c',
    success: '#34c77b',
    'on-success': '#0b0b0c',
    warning: '#f5a524',
    'on-warning': '#0b0b0c',
    'border-color': '#2e2e33',
    accent: '#9E63F6',
    outline: '#3a3a40',
    'outline-variant': '#2e2e33',
    'primary-container': '#163a30',
    'error-container': '#3B1A1A',
  },
}

const FROZEN_NATIVE_PALETTE = {
  'bg-canvas': '#0b0b0c',
  'bg-sidebar': '#151516',
  'bg-topbar': '#18181a',
  'bg-surface': '#1b1b1d',
  'bg-surface-raised': '#232326',
  'border-default': '#2e2e33',
  'border-strong': '#3a3a40',
  'text-primary': '#ededed',
  'text-secondary': '#a0a0a8',
  'text-tertiary': '#6e6e76',
  'text-on-accent': '#0b0b0c',
  'text-inverse': '#ffffff',
  'accent-primary': '#3ecf9e',
  'accent-primary-subtle': '#163a30',
  'status-success': '#34c77b',
  'status-danger': '#f0506e',
  'status-warning': '#f5a524',
  'status-info': '#4c8dff',
  'usp-5': '#3ecf9e',
  'usp-6': '#f472b6',
}

const FROZEN_FILES: ReadonlyArray<readonly [string, string]> = [
  [
    'packages/design-tokens/tokens.css',
    '5541a249e9ea0be4581a61ecb9d1fe2b4879263db371cd8c21a50a497ba74a39',
  ],
  [
    'packages/design-tokens/native.css',
    '081e13b9646d17aa51480f6c8c35ef4ff434f4f192ba046ba7dcf00cab5ec4c7',
  ],
  [
    'packages/design-tokens/src/index.ts',
    '4a39775de44ecd300a39400f3c57274d2be95c38ee55117df1e213f6208160cf',
  ],
  [
    'packages/design-tokens/src/native.ts',
    '97527e43514cb0a118296bf944dcdbcff620727f127328ba185e2e95584af0aa',
  ],
  [
    'packages/design-tokens/src/semantic.ts',
    '652fa23a3535829bf83f1941573f4324beeef6e634d32f927317eaa5ea0a046d',
  ],
  [
    'packages/design-tokens/src/tokens.ts',
    '1e40b4817c33e00c483a18f812d7448fed9552f4578dbfb2a4218b935d239ca3',
  ],
  [
    'packages/design-tokens/src/vuetify.ts',
    'c948bafa43712e57364ebafc0e0cf89b899962bd8ad1c89ed30ad248d8e0a8da',
  ],
  [
    'packages/ui/assets/fonts/inter.css',
    'f2a490359f3d773fcdc999272193e3da2125e7738b1599b988dba6ea2d02aa2b',
  ],
  [
    'apps/main/src/plugins/vuetify.ts',
    '0e8eaf666812909c49427a6b0ee2a5f28d0362044912bc9e658160dd4a52cc94',
  ],
  ['apps/main/src/App.vue', '8fc4e1731b11b8a481d65d087d9710f2b3330b6ac3d1892dbb72a4e859f7611e'],
  [
    'apps/main/src/composables/useTheme.ts',
    '54135e7a10ebe3cb1eb772a85be9ccfb941287895b3505d1dbc14b15cb36ec39',
  ],
  [
    'apps/main/src/composables/useThemePreference.ts',
    '3ec3c4003c84ae3880e5ba409a0c1a8cf62d9204408180e4b89eceeee4a5d734',
  ],
]

describe('shared token surface is frozen (AH-114 spec 3)', () => {
  it('lightTheme is unchanged', () => {
    expect(lightTheme).toStrictEqual(FROZEN_LIGHT)
  })

  it('darkTheme is unchanged', () => {
    expect(darkTheme).toStrictEqual(FROZEN_DARK)
  })

  it('nativeDarkTheme is unchanged', () => {
    expect(nativeDarkTheme).toStrictEqual(FROZEN_NATIVE_DARK)
  })

  it('nativePalette (the source of the agency palette) is unchanged', () => {
    expect(nativePalette).toStrictEqual(FROZEN_NATIVE_PALETTE)
  })

  it.each(FROZEN_FILES)('%s is byte-for-byte unchanged', (file, hash) => {
    expect(sha256(file)).toBe(hash)
  })
})
