/**
 * Component tests for AvailabilityCalendar (Sprint 5 Chunk B).
 *
 * Spot-check anchors covered here:
 *   - occurrences render in the correct day cell for the creator's tz;
 *   - a recurring block's multiple occurrences EACH render, keyed
 *     `id + starts_at` — no collision (D-b5);
 *   - the rendered range is read from `meta.window` (D-b6);
 *   - the empty state shows when there are no blocks;
 *   - clicking a day opens the create dialog seeded with that date.
 *
 * The REAL `CMonthGrid` renders (it is lightweight). The heavy dialog
 * (`VDatePicker` / selects) is stubbed per the jsdom heap guidance from the
 * roster spec — its own behavior is tested in AvailabilityBlockDialog.spec.
 *
 * System time is pinned to mid-June 2026 (Date only, so promise microtasks
 * and Vuetify timers are untouched) so the calendar opens on a known month.
 */

import type { AvailabilityOccurrenceResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enAvailability from '@/core/i18n/locales/en/availability.json'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

vi.mock('../availability.api', () => ({
  availabilityApi: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
  },
}))

import { availabilityApi } from '../availability.api'
import AvailabilityCalendar from './AvailabilityCalendar.vue'

const mockApi = vi.mocked(availabilityApi)

const DialogStub = {
  name: 'AvailabilityBlockDialog',
  props: ['modelValue', 'occurrence', 'initialDate', 'zone'],
  emits: ['saved', 'deleted', 'update:modelValue'],
  template: '<div class="dialog-stub" />',
}

function makeOccurrence(
  id: string,
  startsAt: string,
  endsAt: string,
  overrides: Partial<AvailabilityOccurrenceResource['attributes']> = {},
): AvailabilityOccurrenceResource {
  return {
    id,
    type: 'availability_blocks',
    attributes: {
      starts_at: startsAt,
      ends_at: endsAt,
      is_all_day: false,
      block_type: 'hard',
      kind: 'vacation',
      reason: null,
      is_recurring: false,
      recurrence_rule: null,
      ...overrides,
    },
  }
}

const WINDOW = { from: '2026-05-25T00:00:00Z', to: '2026-07-15T00:00:00Z' }

async function mountCalendar(
  options: {
    occurrences?: AvailabilityOccurrenceResource[]
    timezone?: string | null
    reject?: boolean
  } = {},
) {
  const pinia = createPinia()
  setActivePinia(pinia)

  if (options.reject === true) {
    mockApi.list.mockRejectedValue(new Error('boom'))
  } else {
    mockApi.list.mockResolvedValue({ data: options.occurrences ?? [], meta: { window: WINDOW } })
  }

  const auth = useAuthStore()
  auth.user = {
    id: '01USERULIDXXXXXXXXXXXXXXXXX',
    type: 'users',
    attributes: {
      email: 'creator@example.com',
      email_verified_at: '2026-01-01T00:00:00Z',
      name: 'Test Creator',
      user_type: 'creator',
      preferred_language: 'en',
      preferred_currency: null,
      timezone: options.timezone === undefined ? 'America/New_York' : options.timezone,
    },
  } as never

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/creator/availability',
        name: 'creator.availability',
        component: { template: '<div />' },
      },
    ],
  })
  await router.push('/creator/availability')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp, ...enAvailability } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(AvailabilityCalendar, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: { AvailabilityBlockDialog: DialogStub },
    },
    attachTo: document.createElement('div'),
  })

  await flushPromises()
  return { wrapper, cleanup: () => wrapper.unmount() }
}

describe('AvailabilityCalendar (Sprint 5 Chunk B)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-06-15T12:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
    if (cleanup !== null) {
      cleanup()
      cleanup = null
    }
  })

  it('renders an occurrence in the correct day cell for the creator tz', async () => {
    // 13:30 UTC = 09:30 in New York on 2026-06-15.
    const occ = makeOccurrence('BLOCK_A', '2026-06-15T13:30:00Z', '2026-06-15T14:30:00Z')
    const mounted = await mountCalendar({ occurrences: [occ] })
    cleanup = mounted.cleanup

    const cell = mounted.wrapper.find('[data-date="2026-06-15"]')
    expect(cell.exists()).toBe(true)
    expect(cell.find('[data-test="availability-bar-BLOCK_A|2026-06-15T13:30:00Z"]').exists()).toBe(
      true,
    )
  })

  it('renders EACH occurrence of a recurring block without key collision (D-b5)', async () => {
    // Same block ULID, two weekly instances → two distinct composite keys.
    const week1 = makeOccurrence('BLOCK_R', '2026-06-08T13:00:00Z', '2026-06-08T14:00:00Z', {
      is_recurring: true,
      recurrence_rule: 'FREQ=WEEKLY',
    })
    const week2 = makeOccurrence('BLOCK_R', '2026-06-15T13:00:00Z', '2026-06-15T14:00:00Z', {
      is_recurring: true,
      recurrence_rule: 'FREQ=WEEKLY',
    })
    const mounted = await mountCalendar({ occurrences: [week1, week2] })
    cleanup = mounted.cleanup

    const bar1 = mounted.wrapper.find('[data-test="availability-bar-BLOCK_R|2026-06-08T13:00:00Z"]')
    const bar2 = mounted.wrapper.find('[data-test="availability-bar-BLOCK_R|2026-06-15T13:00:00Z"]')
    expect(bar1.exists()).toBe(true)
    expect(bar2.exists()).toBe(true)
    // Each lands in its own week's cell.
    expect(mounted.wrapper.find('[data-date="2026-06-08"]').html()).toContain(
      'availability-bar-BLOCK_R|2026-06-08T13:00:00Z',
    )
    expect(mounted.wrapper.find('[data-date="2026-06-15"]').html()).toContain(
      'availability-bar-BLOCK_R|2026-06-15T13:00:00Z',
    )
  })

  it('reads the effective range from meta.window (D-b6)', async () => {
    const mounted = await mountCalendar({ occurrences: [] })
    cleanup = mounted.cleanup
    expect((mounted.wrapper.vm as unknown as { loadedWindow: typeof WINDOW }).loadedWindow).toEqual(
      WINDOW,
    )
  })

  it('falls back to the browser tz when the creator tz is null without crashing (D-b7)', async () => {
    const occ = makeOccurrence('BLOCK_N', '2026-06-15T13:30:00Z', '2026-06-15T14:30:00Z')
    const mounted = await mountCalendar({ occurrences: [occ], timezone: null })
    cleanup = mounted.cleanup
    // Some cell renders the block (exact day depends on the runner's browser
    // tz; the precise fallback VALUE is asserted in datetime.spec).
    expect(mounted.wrapper.find('[data-test^="availability-bar-BLOCK_N"]').exists()).toBe(true)
  })

  it('shows the empty state when there are no blocks', async () => {
    const mounted = await mountCalendar({ occurrences: [] })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="availability-empty"]').exists()).toBe(true)
    expect(mounted.wrapper.find('[data-test^="availability-bar-"]').exists()).toBe(false)
  })

  it('opens the create dialog seeded with the clicked day', async () => {
    const mounted = await mountCalendar({ occurrences: [] })
    cleanup = mounted.cleanup

    await mounted.wrapper.find('[data-date="2026-06-10"]').trigger('click')

    const dialog = mounted.wrapper.findComponent(DialogStub)
    expect(dialog.props('modelValue')).toBe(true)
    expect(dialog.props('occurrence')).toBeNull()
    expect(dialog.props('initialDate')).toBe('2026-06-10')
  })

  it('shows the error alert when the list call fails', async () => {
    const mounted = await mountCalendar({ reject: true })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="availability-load-error"]').exists()).toBe(true)
  })
})
