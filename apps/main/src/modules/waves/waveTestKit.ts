/**
 * Shared fixtures for the PL-1.1 specs: a wave, a brand file, and a mount helper that wires Pinia, the
 * router, i18n (English) and Vuetify the way the existing page specs do.
 */
import type { BrandFileResource, WaveResource } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Component } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

export const BRAND_ULID = '01BRANDULIDXXXXXXXXXXXXXX'
export const AGENCY_ULID = 'agency-ulid'
export const WAVE_ULID = '01WAVEULIDXXXXXXXXXXXXXXX'

export function makeWave(
  over: Partial<WaveResource['attributes']> = {},
  linked = false,
): WaveResource {
  return {
    id: WAVE_ULID,
    type: 'waves',
    attributes: {
      name: 'Spring push',
      go_live_on: '2026-11-07',
      markets: ['ES', 'PT'],
      status: 'draft',
      created_at: '2026-11-01T10:00:00.000000Z',
      updated_at: '2026-11-02T10:00:00.000000Z',
      ...over,
    },
    relationships: {
      brand: { data: { id: BRAND_ULID, type: 'brands', attributes: { name: 'Acme Corp' } } },
      campaign: linked
        ? {
            data: {
              id: '01CAMPAIGNULIDXXXXXXXXXX',
              type: 'campaigns',
              attributes: { name: 'Spring campaign', status: 'active' },
            },
          }
        : null,
      created_by: { data: { id: 'user-1', type: 'users', attributes: { name: 'Ada Admin' } } },
    },
  }
}

export function makeFile(
  id = '01FILEULIDXXXXXXXXXXXXXXXX',
  over: Partial<BrandFileResource['attributes']> = {},
): BrandFileResource {
  return {
    id,
    type: 'brand_files',
    attributes: {
      original_filename: 'brief.pdf',
      mime_type: 'application/pdf',
      size_bytes: 2048,
      file_type: 'brief',
      download_url: 'https://files.example.test/brief.pdf?sig=1',
      created_at: '2026-11-03T10:00:00.000000Z',
      ...over,
    },
    relationships: {
      uploaded_by: { data: { id: 'user-1', type: 'users', attributes: { name: 'Ada Admin' } } },
    },
  }
}

export type Role = 'agency_admin' | 'agency_manager' | 'agency_staff'

export interface MountKitOptions {
  role?: Role
  /** `true`, `false`, or `'absent'` (no features block at all). */
  flag?: boolean | 'absent'
  route?: string
}

export async function mountWithApp(
  component: Component,
  props: Record<string, unknown>,
  options: MountKitOptions = {},
): Promise<ReturnType<typeof mount>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  useAgencyStore().initFromUser([
    { agency_id: AGENCY_ULID, agency_name: 'Test Agency', role: options.role ?? 'agency_admin' },
  ])
  const flag = options.flag ?? true
  useAuthStore().user = {
    id: 'user-1',
    type: 'users',
    attributes: { features: flag === 'absent' ? {} : { waves: flag } },
  } as never

  const page = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/brands', name: 'brands.list', component: page },
      { path: '/brands/:ulid', name: 'brands.detail', component: page },
      { path: '/brands/:ulid/edit', name: 'brands.edit', component: page },
      { path: '/brands/:ulid/waves/:waveUlid', name: 'waves.detail', component: page },
      { path: '/campaigns/:ulid', name: 'campaigns.detail', component: page },
    ],
  })
  await router.push(options.route ?? `/brands/${BRAND_ULID}/waves/${WAVE_ULID}`)
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(component, {
    props,
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}
