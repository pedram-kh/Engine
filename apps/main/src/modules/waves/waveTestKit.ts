/**
 * Shared fixtures for the PL-1.1 specs: a wave, a brand file, and a mount helper that wires Pinia, the
 * router, i18n (English) and Vuetify the way the existing page specs do.
 */
import type {
  BrandFileResource,
  WaveProgress,
  WaveResource,
  WaveStepResource,
  WaveStream,
  WaveStreamSummary,
  WaveTrackerResource,
} from '@catalyst/api-client'
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
              attributes: { name: 'Spring campaign', status: 'active', campaign_type: 'for_wave' },
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
  /** PL-2.1: `features.strategy`. Left out (undefined) the key is absent, exactly as before PL-2.1. */
  strategy?: boolean
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
    attributes: {
      features:
        flag === 'absent'
          ? {}
          : options.strategy === undefined
            ? { waves: flag }
            : { waves: flag, strategy: options.strategy },
    },
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
      {
        path: '/brands/:ulid/waves/:waveUlid/strategy/usps',
        name: 'strategy.usps',
        component: page,
      },
      {
        path: '/brands/:ulid/waves/:waveUlid/strategy/personas',
        name: 'strategy.personas',
        component: page,
      },
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

export const TODAY = '2026-11-18'

let stepCounter = 0

export function makeStep(
  over: Partial<WaveStepResource['attributes']> = {},
  id?: string,
): WaveStepResource {
  stepCounter += 1
  return {
    id: id ?? `01STEP${String(stepCounter).padStart(20, '0')}`,
    type: 'wave_steps',
    attributes: {
      stream: 'ugc_video',
      key: null,
      is_strategy: false,
      name: `Step ${stepCounter}`,
      position: stepCounter * 10,
      status: 'not_started',
      status_source: 'system',
      display_status: 'up_next',
      days_overdue: 0,
      starts_on: null,
      due_on: null,
      completed_on: null,
      owner: null,
      owner_client: false,
      owner_is_former: false,
      ...over,
    },
  }
}

export const NO_PROGRESS: WaveProgress = {
  segments: ['empty', 'empty', 'empty', 'empty', 'empty', 'empty'],
  label_kind: 'not_started',
  step_name: null,
}

/** A tracker whose stream summaries are derived from the steps the way the server does (done, total, status). */
export function makeTracker(
  steps: WaveStepResource[],
  over: Partial<WaveTrackerResource['attributes']> = {},
): WaveTrackerResource {
  const order: WaveStream[] = ['ugc_video', 'statics', 'stills']
  const colours: Record<WaveStream, string> = {
    ugc_video: '#3ecf9e',
    statics: '#8b6cff',
    stills: '#f472b6',
  }
  const streams: WaveStreamSummary[] = []
  for (const stream of order) {
    const mine = steps.filter((s) => s.attributes.stream === stream)
    if (mine.length === 0) continue
    const open = mine.filter((s) => s.attributes.status !== 'done')
    const delayed = Math.max(0, ...open.map((s) => s.attributes.days_overdue))
    const next = open[0]
    streams.push({
      stream,
      colour: colours[stream],
      status: open.length === 0 ? 'complete' : delayed > 0 ? 'delayed' : 'on_track',
      days_delayed: delayed,
      done: mine.length - open.length,
      total: mine.length,
      next_step:
        next === undefined
          ? null
          : { id: next.id, name: next.attributes.name, due_on: next.attributes.due_on },
      final_delivery: mine[mine.length - 1]?.attributes.due_on ?? null,
      owners: [],
      has_client: mine.some((s) => s.attributes.owner_client),
    })
  }
  const delayedStreams = streams.filter((s) => s.status === 'delayed').length
  return {
    id: WAVE_ULID,
    type: 'wave_trackers',
    attributes: {
      today: TODAY,
      streams,
      absent_streams: order.filter((o) => !streams.some((s) => s.stream === o)),
      steps,
      health: {
        status: delayedStreams > 0 ? 'at_risk' : 'on_track',
        delayed_streams: delayedStreams,
      },
      progress: NO_PROGRESS,
      ...over,
    },
  }
}
