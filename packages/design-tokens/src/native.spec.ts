/**
 * AH-100 D4 — the creator app's token set: TS/CSS lockstep, WCAG AA on
 * the critical pairs, slot coverage against the web dark theme, and the
 * guarantee that building it leaves the web themes untouched.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { colord, extend } from 'colord'
import a11yPlugin from 'colord/plugins/a11y'
import { describe, expect, it } from 'vitest'

import { nativeCssVariables, nativeDarkTheme, nativePalette } from './native'
import { darkTheme } from './vuetify'

extend([a11yPlugin])

const NATIVE_CSS = readFileSync(path.resolve(__dirname, '../native.css'), 'utf8')
const AA_NORMAL = 4.5

function declared(): Map<string, string> {
  const out = new Map<string, string>()
  for (const match of NATIVE_CSS.matchAll(/--native-([a-z0-9-]+):\s*([^;]+);/g)) {
    out.set(match[1] as string, (match[2] as string).trim())
  }
  return out
}

describe('native.css ↔ nativeCssVariables lockstep', () => {
  it('declares exactly the TS variable set, with identical values', () => {
    expect(Object.fromEntries(declared())).toEqual(nativeCssVariables)
  })
})

describe('nativeDarkTheme', () => {
  it('registers every slot the web dark theme registers', () => {
    expect(Object.keys(nativeDarkTheme.colors).sort()).toEqual(Object.keys(darkTheme.colors).sort())
    expect(nativeDarkTheme.dark).toBe(true)
  })

  it('carries the Figma palette in the slots Figma defines', () => {
    expect(nativeDarkTheme.colors).toMatchObject({
      background: nativePalette['bg-canvas'],
      surface: nativePalette['bg-surface'],
      'on-surface': nativePalette['text-primary'],
      primary: nativePalette['accent-primary'],
      error: nativePalette['status-danger'],
      success: nativePalette['status-success'],
      warning: nativePalette['status-warning'],
      info: nativePalette['status-info'],
      'border-color': nativePalette['border-default'],
    })
  })

  it('only parses as valid CSS colours', () => {
    for (const value of Object.values(nativeDarkTheme.colors)) {
      expect(colord(value).isValid(), value).toBe(true)
    }
  })

  it('leaves the web dark theme untouched', () => {
    expect(darkTheme.colors.primary).toBe('#2ECDAE')
    expect(darkTheme.colors.background).not.toBe(nativeDarkTheme.colors.background)
  })

  it.each([
    ['background', 'on-background'],
    ['surface', 'on-surface'],
    ['surface', 'on-surface-variant'],
    ['surface-bright', 'on-surface'],
    ['primary', 'on-primary'],
    ['secondary', 'on-secondary'],
    ['error', 'on-error'],
    ['success', 'on-success'],
    ['warning', 'on-warning'],
    ['info', 'on-info'],
  ])('%s / %s meets WCAG AA-normal', (bg, fg) => {
    const ratio = colord(nativeDarkTheme.colors[fg] as string).contrast(
      nativeDarkTheme.colors[bg] as string,
    )
    expect(ratio).toBeGreaterThanOrEqual(AA_NORMAL)
  })
})
