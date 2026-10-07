/**
 * AH-100 — the creator app's availability shell (Figma frame 12): compact
 * grid marks + the month agenda, its same-data parity with the web
 * calendar, the page's platform swap, and the block form as a bottom sheet.
 *
 * Harness mirrors AvailabilityCalendar.spec: the REAL `CMonthGrid`, the
 * dialog stubbed, Date pinned to mid-June 2026, zone UTC so day keys are
 * read straight off the fixtures.
 */

import type { AvailabilityOccurrenceResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enAvailability from '@/core/i18n/locales/en/availability.json'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../availability.api', () => ({
  availabilityApi: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
  },
}))

import { availabilityApi } from '../availability.api'
import AvailabilityPage from '../pages/AvailabilityPage.vue'
import AvailabilityBlockDialog from './AvailabilityBlockDialog.vue'
import AvailabilityCalendar from './AvailabilityCalendar.vue'
import AvailabilityCalendarNative from './AvailabilityCalendarNative.vue'

const mockApi = vi.mocked(availabilityApi)

const DialogStub = {
  name: 'AvailabilityBlockDialog',
  props: ['modelValue', 'occurrence', 'initialDate', 'zone'],
  emits: ['saved', 'deleted', 'update:modelValue'],
  template: '<div class="dialog-stub" />',
}

const WINDOW = { from: '2026-05-25T00:00:00Z', to: '2026-07-15T00:00:00Z' }

function occ(
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

/** A June 2026 month that exercises every mark and the D-b5 composite key. */
const FIXTURE: AvailabilityOccurrenceResource[] = [
  // All-day HARD, spanning 8–9 June (end-exclusive at midnight on the 10th).
  occ('ALLDAY_H', '2026-06-08T00:00:00Z', '2026-06-10T00:00:00Z', { is_all_day: true }),
  // Timed SOFT on the 12th only.
  occ('TIMED_S', '2026-06-12T09:00:00Z', '2026-06-12T10:00:00Z', { block_type: 'soft' }),
  // A recurring block: two occurrences share the ULID.
  occ('REC', '2026-06-16T13:00:00Z', '2026-06-16T14:00:00Z', { is_recurring: true }),
  occ('REC', '2026-06-23T13:00:00Z', '2026-06-23T14:00:00Z', { is_recurring: true }),
]

async function mountWith(
  component: Component,
  options: { occurrences?: AvailabilityOccurrenceResource[]; reject?: boolean } = {},
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
      timezone: 'UTC',
    },
  } as never

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp, ...enAvailability } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(component, {
    global: {
      plugins: [pinia, i18n, vuetify],
      stubs: { AvailabilityBlockDialog: DialogStub },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

function dialog(wrapper: Awaited<ReturnType<typeof mountWith>>) {
  return wrapper.findComponent({ name: 'AvailabilityBlockDialog' })
}

describe('AvailabilityCalendarNative (AH-100)', () => {
  let wrapper: Awaited<ReturnType<typeof mountWith>> | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-06-15T12:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
    wrapper?.unmount()
    wrapper = null
    platform.native = false
  })

  it('marks blocked days in the grid: tint for all-day cover, dot for timed-only', async () => {
    wrapper = await mountWith(AvailabilityCalendarNative, { occurrences: FIXTURE })
    const mark = (date: string) => wrapper!.find(`[data-test="availability-native-mark-${date}"]`)

    expect(mark('2026-06-08').classes()).toContain('availability-native__mark--fill-hard')
    expect(mark('2026-06-09').classes()).toContain('availability-native__mark--fill-hard')
    expect(mark('2026-06-10').exists()).toBe(false)
    expect(mark('2026-06-12').classes()).toContain('availability-native__mark--dot-soft')
    expect(mark('2026-06-16').classes()).toContain('availability-native__mark--dot-hard')
    // The grid cells carry marks only — never the web calendar's chips.
    expect(wrapper.find('[data-test^="availability-bar-"]').exists()).toBe(false)
  })

  it('lists the month by day, in order, each occurrence keyed id|starts_at', async () => {
    wrapper = await mountWith(AvailabilityCalendarNative, { occurrences: FIXTURE })

    const days = wrapper
      .findAll('[data-test^="availability-agenda-day-"]')
      .map((d) => d.attributes('data-test'))
    expect(days).toEqual([
      'availability-agenda-day-2026-06-08',
      'availability-agenda-day-2026-06-09',
      'availability-agenda-day-2026-06-12',
      'availability-agenda-day-2026-06-16',
      'availability-agenda-day-2026-06-23',
    ])
    expect(wrapper.find('[data-test="availability-agenda-day-2026-06-08"]').text()).toContain(
      'Mon, Jun 8',
    )
    expect(
      wrapper.find('[data-test="availability-agenda-item-REC|2026-06-16T13:00:00Z"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-test="availability-agenda-item-REC|2026-06-23T13:00:00Z"]').exists(),
    ).toBe(true)
  })

  it("marks a neighbouring month's padding day but leaves it out of this month's agenda", async () => {
    const july = occ('JULY', '2026-07-02T09:00:00Z', '2026-07-02T10:00:00Z')
    wrapper = await mountWith(AvailabilityCalendarNative, { occurrences: [july] })

    expect(wrapper.find('[data-test="availability-native-mark-2026-07-02"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="availability-agenda"]').exists()).toBe(false)
  })

  it('a tapped agenda row edits that series; a tapped day creates on it', async () => {
    wrapper = await mountWith(AvailabilityCalendarNative, { occurrences: FIXTURE })

    await wrapper
      .find('[data-test="availability-agenda-item-TIMED_S|2026-06-12T09:00:00Z"]')
      .trigger('click')
    expect(dialog(wrapper).props('modelValue')).toBe(true)
    expect(dialog(wrapper).props('occurrence')).toMatchObject({ id: 'TIMED_S' })

    await wrapper.find('[data-date="2026-06-20"]').trigger('click')
    expect(dialog(wrapper).props('occurrence')).toBeNull()
    expect(dialog(wrapper).props('initialDate')).toBe('2026-06-20')
  })

  it('shows the empty state and no agenda when the month has no blocks', async () => {
    wrapper = await mountWith(AvailabilityCalendarNative)

    expect(wrapper.find('[data-test="availability-empty"]').text()).toContain(
      'No availability blocks yet',
    )
    expect(wrapper.find('[data-test="availability-agenda"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="availability-calendar-native"]').text()).toContain('Today')
  })

  it('surfaces a load failure', async () => {
    wrapper = await mountWith(AvailabilityCalendarNative, { reject: true })
    expect(wrapper.find('[data-test="availability-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="availability-empty"]').exists()).toBe(false)
  })
})

/**
 * The two shells are views of one `useAvailabilityMonth`: for the same API
 * payload they must show the same set of occurrences. (Fixture is all
 * in-month with ≤3 timed blocks a day, so the web cell cap hides nothing.)
 */
describe('availability shells — same data (AH-100)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-06-15T12:00:00Z'))
  })
  afterEach(() => vi.useRealTimers())

  it('web chips and native agenda rows carry the same occurrence keys', async () => {
    const web = await mountWith(AvailabilityCalendar, { occurrences: FIXTURE })
    const webKeys = new Set(
      web
        .findAll('[data-test^="availability-bar-"]')
        .map((el) => (el.attributes('data-test') ?? '').replace('availability-bar-', '')),
    )
    web.unmount()

    const native = await mountWith(AvailabilityCalendarNative, { occurrences: FIXTURE })
    const nativeKeys = new Set(
      native
        .findAll('[data-test^="availability-agenda-item-"]')
        .map((el) => (el.attributes('data-test') ?? '').replace('availability-agenda-item-', '')),
    )
    native.unmount()

    expect(webKeys.size).toBe(4)
    expect(nativeKeys).toEqual(webKeys)
  })
})

describe('AvailabilityPage — platform swap (AH-100)', () => {
  afterEach(() => {
    platform.native = false
  })

  async function mountPage() {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: { en: { ...enAvailability } } as never,
    }) as unknown as ReturnType<typeof createI18n>
    return mount(AvailabilityPage, {
      global: {
        plugins: [i18n],
        stubs: {
          AvailabilityCalendar: { name: 'AvailabilityCalendar', template: '<div />' },
          AvailabilityCalendarNative: { name: 'AvailabilityCalendarNative', template: '<div />' },
        },
      },
    })
  }

  it('native: the compact shell and the frame-12 header', async () => {
    platform.native = true
    const page = await mountPage()
    expect(page.findComponent({ name: 'AvailabilityCalendarNative' }).exists()).toBe(true)
    expect(page.findComponent({ name: 'AvailabilityCalendar' }).exists()).toBe(false)
    expect(page.find('header').classes()).toContain('availability-page__header--native')
  })

  it('web: the month calendar, header unchanged', async () => {
    const page = await mountPage()
    expect(page.findComponent({ name: 'AvailabilityCalendar' }).exists()).toBe(true)
    expect(page.findComponent({ name: 'AvailabilityCalendarNative' }).exists()).toBe(false)
    expect(page.find('header').classes()).toEqual(['availability-page__header'])
  })
})

describe('AvailabilityBlockDialog — container (AH-100)', () => {
  afterEach(() => {
    platform.native = false
  })

  function mountDialog() {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: { en: { ...enAvailability } } as never,
    }) as unknown as ReturnType<typeof createI18n>
    const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
    return mount(AvailabilityBlockDialog, {
      props: { modelValue: true, occurrence: null, initialDate: '2026-06-20', zone: 'UTC' },
      global: {
        plugins: [i18n, vuetify],
        stubs: {
          DateTimeField: { template: '<div />' },
          VSelect: { template: '<div />' },
          VSwitch: { template: '<div />' },
          VDialog: { name: 'VDialog', template: '<div class="vdialog-stub"><slot /></div>' },
        },
      },
      attachTo: document.createElement('div'),
    })
  }

  it('native: the form opens in a bottom sheet', () => {
    platform.native = true
    const sheet = mountDialog()
    expect(sheet.findComponent({ name: 'VBottomSheet' }).exists()).toBe(true)
    expect(sheet.find('[data-test="availability-dialog-title"]').exists()).toBe(true)
    sheet.unmount()
  })

  it('web: the centred dialog, no bottom sheet', () => {
    const centred = mountDialog()
    expect(centred.findComponent({ name: 'VBottomSheet' }).exists()).toBe(false)
    expect(centred.findComponent({ name: 'VDialog' }).exists()).toBe(true)
    expect(centred.find('[data-test="availability-dialog-title"]').exists()).toBe(true)
    centred.unmount()
  })
})
