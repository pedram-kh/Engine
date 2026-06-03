/**
 * Component tests for AvailabilityBlockDialog (Sprint 5 Chunk B).
 *
 * Spot-check anchors covered here:
 *   - create submits a valid block (tz-correct UTC instants) — D-b7/D-b9;
 *   - `assignment_auto` is NOT offered in the kind options — D-b9;
 *   - the series-edit "applies to all occurrences" notice shows for a
 *     recurring block and not for a one-off — D-b8;
 *   - validation errors map to fields via extractFieldErrors — D-b9;
 *   - a recurring submit emits a valid weekly rule — D-b11.
 *
 * Heavy Vuetify (VDialog overlay, VSelect, VSwitch) and the local
 * DateTimeField (which wraps VDatePicker) are stubbed per the jsdom heap
 * guidance; the tz/recurrence MATH is asserted in datetime.spec /
 * recurrence.spec, so the dialog tests focus on wiring.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enAvailability from '@/core/i18n/locales/en/availability.json'

vi.mock('../availability.api', () => ({
  availabilityApi: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
  },
}))

import { availabilityApi } from '../availability.api'
import { zonedToUtcIso } from '../datetime'
import AvailabilityBlockDialog from './AvailabilityBlockDialog.vue'

const mockApi = vi.mocked(availabilityApi)
const NY = 'America/New_York'

const DateTimeFieldStub = {
  name: 'DateTimeField',
  props: [
    'date',
    'time',
    'showTime',
    'dateLabel',
    'timeLabel',
    'dateErrors',
    'timeErrors',
    'dataTestPrefix',
  ],
  emits: ['update:date', 'update:time'],
  template: '<div class="dtf-stub" />',
}

const VSelectStub = {
  name: 'VSelect',
  props: ['items', 'modelValue', 'label', 'errorMessages', 'itemTitle', 'itemValue'],
  emits: ['update:modelValue'],
  template: '<div class="vselect-stub" />',
}

const VSwitchStub = {
  name: 'VSwitch',
  props: ['modelValue', 'label'],
  emits: ['update:modelValue'],
  template: `<button class="vswitch-stub" @click="$emit('update:modelValue', !modelValue)" />`,
}

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

function occurrence(overrides: Record<string, unknown> = {}) {
  return {
    id: '01BLOCKULIDXXXXXXXXXXXXXXXX',
    type: 'availability_blocks' as const,
    attributes: {
      starts_at: '2026-06-15T13:00:00Z',
      ends_at: '2026-06-15T14:00:00Z',
      is_all_day: false,
      block_type: 'hard' as const,
      kind: 'vacation' as const,
      reason: null,
      is_recurring: false,
      recurrence_rule: null,
      ...overrides,
    },
  }
}

function mountDialog(props: Record<string, unknown> = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enAvailability } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(AvailabilityBlockDialog, {
    props: {
      modelValue: true,
      occurrence: null,
      initialDate: '2026-06-20',
      zone: NY,
      ...props,
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: {
        DateTimeField: DateTimeFieldStub,
        VSelect: VSelectStub,
        VSwitch: VSwitchStub,
        VDialog: VDialogStub,
      },
    },
    attachTo: document.createElement('div'),
  })
  return wrapper
}

describe('AvailabilityBlockDialog (Sprint 5 Chunk B)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('create: submits a valid block with tz-correct UTC instants', async () => {
    mockApi.create.mockResolvedValue({ data: occurrence() })
    const wrapper = mountDialog()

    await wrapper.find('[data-test="availability-submit"]').trigger('click')
    await flushPromises()

    expect(mockApi.create).toHaveBeenCalledTimes(1)
    const payload = mockApi.create.mock.calls[0]![0]
    expect(payload).toMatchObject({
      starts_at: zonedToUtcIso('2026-06-20', '09:00', NY),
      ends_at: zonedToUtcIso('2026-06-20', '10:00', NY),
      is_all_day: false,
      block_type: 'hard',
      kind: 'vacation',
      reason: null,
      is_recurring: false,
    })
    // A non-recurring block carries no recurrence_rule.
    expect(Object.prototype.hasOwnProperty.call(payload, 'recurrence_rule')).toBe(false)
    wrapper.unmount()
  })

  it('does NOT offer assignment_auto in the kind options (D-b9)', () => {
    const wrapper = mountDialog()
    const selects = wrapper.findAllComponents(VSelectStub)
    // The kind select is the one whose items include 'vacation'.
    const kindSelect = selects.find((s) =>
      ((s.props('items') as { value: string }[]) ?? []).some((i) => i.value === 'vacation'),
    )
    expect(kindSelect).toBeDefined()
    const values = (kindSelect!.props('items') as { value: string }[]).map((i) => i.value)
    expect(values).toEqual(['vacation', 'personal', 'exclusive_contract', 'other'])
    expect(values).not.toContain('assignment_auto')
    wrapper.unmount()
  })

  it('shows the series-edit notice when editing a recurring block (D-b8)', () => {
    const wrapper = mountDialog({
      occurrence: occurrence({ is_recurring: true, recurrence_rule: 'FREQ=WEEKLY' }),
      initialDate: null,
    })
    expect(wrapper.find('[data-test="availability-series-notice"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('hides the series-edit notice for a one-off block', () => {
    const wrapper = mountDialog({ occurrence: occurrence(), initialDate: null })
    expect(wrapper.find('[data-test="availability-series-notice"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('maps 422 field errors onto the start field via extractFieldErrors (D-b9)', async () => {
    mockApi.create.mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'Validation failed.',
        details: [
          {
            code: 'validation.after',
            detail: 'The end must be after the start.',
            source: { pointer: '/data/attributes/ends_at' },
          },
        ],
      }),
    )
    const wrapper = mountDialog()

    await wrapper.find('[data-test="availability-submit"]').trigger('click')
    await flushPromises()

    const endField = wrapper
      .findAllComponents(DateTimeFieldStub)
      .find((f) => f.props('dataTestPrefix') === 'availability-end')
    expect(endField).toBeDefined()
    expect(endField!.props('dateErrors')).toContain('The end must be after the start.')
    wrapper.unmount()
  })

  it('recurring submit emits a valid weekly rule (D-b11)', async () => {
    mockApi.create.mockResolvedValue({ data: occurrence() })
    const wrapper = mountDialog()

    // Toggle the "repeats weekly" switch on.
    const recurringSwitch = wrapper
      .findAllComponents(VSwitchStub)
      .find((s) => s.props('label') === enAvailability.availability.dialog.fields.recurring)
    expect(recurringSwitch).toBeDefined()
    await recurringSwitch!.trigger('click')

    await wrapper.find('[data-test="availability-submit"]').trigger('click')
    await flushPromises()

    const payload = mockApi.create.mock.calls[0]![0]
    expect(payload.is_recurring).toBe(true)
    expect(payload.recurrence_rule).toBe('FREQ=WEEKLY')
    wrapper.unmount()
  })

  it('delete requires a confirm click, then calls the API (series-level, D-b8)', async () => {
    mockApi.delete.mockResolvedValue(undefined)
    const wrapper = mountDialog({
      occurrence: occurrence({ is_recurring: true, recurrence_rule: 'FREQ=WEEKLY' }),
      initialDate: null,
    })

    const deleteBtn = wrapper.find('[data-test="availability-delete"]')
    await deleteBtn.trigger('click') // arms confirmation
    expect(mockApi.delete).not.toHaveBeenCalled()
    await deleteBtn.trigger('click') // confirms
    await flushPromises()
    expect(mockApi.delete).toHaveBeenCalledWith('01BLOCKULIDXXXXXXXXXXXXXXXX')
    wrapper.unmount()
  })
})
