import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enNotifications from '@/core/i18n/locales/en/notifications.json'

const state = vi.hoisted(() => ({ available: true }))
const registration = vi.hoisted(() => ({
  permission: { value: 'prompt' } as { value: string },
  refreshPermission: vi.fn<() => Promise<string>>(),
  accept: vi.fn<() => Promise<string>>(),
}))
const factory = vi.hoisted(() => vi.fn())

vi.mock('@/core/platform/push/pushChannelAvailable', () => ({
  pushChannelAvailable: () => state.available,
}))
vi.mock('@/core/platform/push/usePushRegistration', () => ({
  usePushRegistration: () => {
    factory()
    return registration
  },
}))

import PushStateRow from './PushStateRow.vue'

function mountRow() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enNotifications } as never,
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(PushStateRow, {
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

beforeEach(() => {
  state.available = true
  // A real ref so the template reacts when the registration flips it.
  registration.permission = ref('prompt') as unknown as { value: string }
  registration.refreshPermission
    .mockReset()
    .mockImplementation(async () => registration.permission.value)
  registration.accept.mockReset().mockResolvedValue('granted')
  factory.mockReset()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('PushStateRow', () => {
  it('prompt → says push is off and offers "Turn on" (the soft-ask second entry)', async () => {
    const wrapper = mountRow()
    await flushPromises()

    expect(wrapper.find('[data-test="push-state-row"]').attributes('data-state')).toBe('prompt')
    expect(wrapper.find('[data-test="push-state-text"]').text()).toBe(
      'Push notifications are off on this device',
    )
    expect(wrapper.find('[data-test="push-state-enable"]').text()).toBe('Turn on')
    wrapper.unmount()
  })

  it('"Turn on" asks the registration to accept (OS prompt + register) once', async () => {
    const wrapper = mountRow()
    await flushPromises()

    await wrapper.find('[data-test="push-state-enable"]').trigger('click')
    await flushPromises()

    expect(registration.accept).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('denied → explains it is blocked in device settings, with NO button (no dead control)', async () => {
    registration.permission.value = 'denied'
    const wrapper = mountRow()
    await flushPromises()

    expect(wrapper.find('[data-test="push-state-row"]').attributes('data-state')).toBe('denied')
    expect(wrapper.find('[data-test="push-state-text"]').text()).toBe(
      'Push notifications are blocked in your device settings',
    )
    expect(wrapper.find('[data-test="push-state-enable"]').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
    wrapper.unmount()
  })

  it.each(['granted', 'unknown', 'unsupported'])('%s → renders nothing', async (permission) => {
    registration.permission.value = permission
    const wrapper = mountRow()
    await flushPromises()

    expect(wrapper.find('[data-test="push-state-row"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('reflects a grant live: the row disappears once the registration flips to granted', async () => {
    const wrapper = mountRow()
    await flushPromises()
    expect(wrapper.find('[data-test="push-state-row"]').exists()).toBe(true)

    registration.permission.value = 'granted'
    await flushPromises()

    expect(wrapper.find('[data-test="push-state-row"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('re-reads the permission when the app returns to the foreground, not while hidden', async () => {
    const wrapper = mountRow()
    await flushPromises()
    registration.refreshPermission.mockClear()

    Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true })
    document.dispatchEvent(new Event('visibilitychange'))
    expect(registration.refreshPermission).not.toHaveBeenCalled()

    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
    document.dispatchEvent(new Event('visibilitychange'))
    expect(registration.refreshPermission).toHaveBeenCalledTimes(1)

    wrapper.unmount()
    registration.refreshPermission.mockClear()
    document.dispatchEvent(new Event('visibilitychange'))
    expect(registration.refreshPermission).not.toHaveBeenCalled()
  })

  it('renders nothing and consults nothing where push is unavailable', async () => {
    state.available = false
    const wrapper = mountRow()
    await flushPromises()

    expect(wrapper.find('[data-test="push-state-row"]').exists()).toBe(false)
    expect(factory).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
