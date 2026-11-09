/**
 * AH-115 L0.5 — `creator-light` parity with the agency palette.
 *
 * Creator code must not import `modules/agency/` (`agency-theme-isolation`), so the creator
 * light palette is a COPY of the agency light palette. This spec (which may import both)
 * is what keeps the copy honest, in three ways:
 *
 *   1. every colour in `creator-light` equals the same key in `agency-light`, and the key
 *      sets are identical;
 *   2. `creator-dark` is `nativeDarkTheme`'s colours, key for key (the phone palette);
 *   3. the light `--native-*` remap in `creator.css` is the same palette expressed as the
 *      native tokens, token by token (canvas = background, topbar = topbar, and so on).
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { nativeDarkTheme } from '@catalyst/design-tokens/native'
import { describe, expect, it } from 'vitest'

import { AGENCY_LIGHT_THEME, agencyColors } from '@/modules/agency/theme/agencyThemes'
import {
  CREATOR_DARK_THEME,
  CREATOR_LIGHT_THEME,
  creatorColors,
} from '@/modules/creators/theme/creatorThemes'

const CREATOR_CSS = readFileSync(
  path.resolve(__dirname, '../../../src/modules/creators/theme/creator.css'),
  'utf8',
)

/** The `--native-*` declarations inside the light remap block. */
function lightRemap(): Record<string, string> {
  const block = /\.v-theme--creator-light\s*\{([^{}]*)\}/.exec(
    CREATOR_CSS.replace(/\/\*[\s\S]*?\*\//g, ''),
  )
  expect(block, 'the creator-light remap block exists').not.toBeNull()
  const out: Record<string, string> = {}
  for (const match of (block?.[1] ?? '').matchAll(/--native-([\w-]+):\s*([^;]+);/g)) {
    out[match[1] as string] = (match[2] as string).trim().toLowerCase()
  }
  return out
}

describe('creator-light parity', () => {
  const creator = creatorColors()[CREATOR_LIGHT_THEME]
  const agency = agencyColors()[AGENCY_LIGHT_THEME]

  it('has exactly the agency light key set', () => {
    expect(Object.keys(creator).sort()).toEqual(Object.keys(agency).sort())
  })

  it.each(Object.keys(agency))('%s equals the agency light value', (key) => {
    expect(creator[key]?.toLowerCase()).toBe(agency[key]?.toLowerCase())
  })

  it('creator-dark is the native dark palette, key for key', () => {
    expect(creatorColors()[CREATOR_DARK_THEME]).toEqual(nativeDarkTheme.colors)
  })

  describe('the light --native-* remap in creator.css', () => {
    const remap = lightRemap()

    it.each([
      ['bg-canvas', 'background'],
      ['bg-sidebar', 'sidebar'],
      ['bg-topbar', 'topbar'],
      ['bg-surface', 'surface'],
      ['bg-surface-raised', 'surface-bright'],
      ['border-default', 'border-color'],
      ['border-strong', 'outline'],
      ['text-primary', 'on-surface'],
      ['text-secondary', 'on-surface-variant'],
      ['text-tertiary', 'text-tertiary'],
      ['accent-primary-subtle', 'primary-container'],
    ])('--native-%s equals the %s colour', (token, colour) => {
      expect(remap[token]).toBe(creator[colour]?.toLowerCase())
    })

    it('does not redefine brand or status fills, radius, type or safe-area tokens', () => {
      const allowed = new Set([
        'bg-canvas',
        'bg-sidebar',
        'bg-topbar',
        'bg-surface',
        'bg-surface-raised',
        'border-default',
        'border-strong',
        'text-primary',
        'text-secondary',
        'text-tertiary',
        'accent-primary-subtle',
        'shadow-overlay',
      ])
      expect(Object.keys(remap).filter((token) => !allowed.has(token))).toEqual([])
    })
  })
})
