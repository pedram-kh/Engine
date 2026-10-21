/**
 * AH-104b-del B5 — the public `/delete-account` page.
 *
 * Public (no guards, auth layout, renders signed out), says the same things as the in-app
 * section, takes the support address from the one config module, and is NOT inside the
 * universal-link scope: `/creator/*` in the iOS association file, `/creator/` in the Android
 * manifest.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { SUPPORT_EMAIL } from '@/core/config/support'
import { APP_LINK_PATH_PREFIX } from '@/core/platform/links/appLink'
import enCreator from '@/core/i18n/locales/en/creator.json'
import { authRoutes } from '@/modules/auth/routes'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import DeleteAccountInfoPage from './DeleteAccountInfoPage.vue'

const APP_ROOT = resolve(__dirname, '../../../..')

describe('/delete-account (public page)', () => {
  let teardown: (() => void) | null = null
  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders signed out, with both ways in, what is deleted and kept, and the 14 days', async () => {
    const h = await mountAuthPage(DeleteAccountInfoPage, { initialRoute: '/delete-account' })
    teardown = h.unmount
    await flushPromises()

    const text = h.wrapper.text()
    expect(h.wrapper.find('[data-test="delete-account-info-title"]').text()).toBe(
      'Delete your account',
    )
    expect(h.wrapper.find('[data-test="delete-account-info-in-app"]').text()).toContain('Security')
    expect(h.wrapper.find('[data-test="delete-account-info-erased"]').text()).toBe(
      enCreator.creator.ui.security.delete.erased,
    )
    expect(h.wrapper.find('[data-test="delete-account-info-kept"]').text()).toBe(
      enCreator.creator.ui.security.delete.kept,
    )
    expect(h.wrapper.find('[data-test="delete-account-info-grace"]').text()).toContain('14 days')
    expect(text).not.toMatch(/\{\w+\}/) // no unfilled placeholder
  })

  it('takes the support address from the config module, as text and as a mailto link', async () => {
    const h = await mountAuthPage(DeleteAccountInfoPage, { initialRoute: '/delete-account' })
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="delete-account-info-support"]').text()).toContain(
      SUPPORT_EMAIL,
    )
    expect(h.wrapper.find('[data-test="delete-account-info-mailto"]').attributes('href')).toBe(
      `mailto:${SUPPORT_EMAIL}`,
    )
  })

  it('renders in another language too (the copy is in every bundle)', async () => {
    const h = await mountAuthPage(DeleteAccountInfoPage, {
      initialRoute: '/delete-account',
      locale: 'pt',
    })
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="delete-account-info-title"]').text()).not.toBe(
      'Delete your account',
    )
  })

  it('is a public route: auth layout, no guards, and not under /creator/', () => {
    const route = authRoutes.find((r) => r.name === 'auth.delete-account-info')

    expect(route, 'the route must exist').toBeDefined()
    expect(route?.path).toBe('/delete-account')
    expect(route?.meta?.layout).toBe('auth')
    expect(route?.meta?.guards ?? []).toEqual([])
    expect(route?.path.startsWith(APP_LINK_PATH_PREFIX)).toBe(false)
  })

  it('is outside both universal-link scopes (iOS association file, Android manifest)', () => {
    const aasa = JSON.parse(
      readFileSync(resolve(APP_ROOT, 'public/.well-known/apple-app-site-association'), 'utf8'),
    ) as { applinks: { details: Array<{ components: Array<Record<string, string>> }> } }
    for (const component of aasa.applinks.details.flatMap((d) => d.components)) {
      const prefix = (component['/'] ?? '').replace(/\*$/u, '')
      expect('/delete-account'.startsWith(prefix)).toBe(false)
    }

    const manifest = readFileSync(
      resolve(APP_ROOT, 'android/app/src/main/AndroidManifest.xml'),
      'utf8',
    )
    for (const match of manifest.matchAll(/android:pathPrefix="([^"]+)"/g)) {
      expect('/delete-account'.startsWith(match[1] as string)).toBe(false)
    }
  })
})
