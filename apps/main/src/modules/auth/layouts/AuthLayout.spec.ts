import { describe, expect, it, afterEach } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import AuthLayout from './AuthLayout.vue'

describe('AuthLayout', () => {
  let teardown: (() => void) | null = null

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the brand mark from app.title', async () => {
    const harness = await mountAuthPage(AuthLayout)
    teardown = harness.unmount
    expect(harness.wrapper.find('[data-test="auth-brand"]').text()).toBe('Catalyst Engine')
  })

  it('exposes the locale switcher', async () => {
    const harness = await mountAuthPage(AuthLayout)
    teardown = harness.unmount
    const switcher = harness.wrapper.find('[data-test="auth-locale-switcher"]')
    expect(switcher.exists()).toBe(true)
  })

  it('renders default slot content inside the centred card', async () => {
    const harness = await mountAuthPage({
      components: { AuthLayout },
      template: `<AuthLayout><div data-test="slot-child">slot ok</div></AuthLayout>`,
    })
    teardown = harness.unmount
    expect(harness.wrapper.find('[data-test="slot-child"]').text()).toBe('slot ok')
  })

  it('switches to Portuguese when locale is set to pt', async () => {
    const harness = await mountAuthPage(AuthLayout, { locale: 'pt' })
    teardown = harness.unmount
    expect(harness.wrapper.find('[data-test="auth-brand"]').text()).toBe('Catalyst Engine')
    // Switcher label flipped — assert via the rendered DOM.
    expect(harness.wrapper.html()).toContain('Idioma')
  })

  it('switches to Italian when locale is set to it', async () => {
    const harness = await mountAuthPage(AuthLayout, { locale: 'it' })
    teardown = harness.unmount
    expect(harness.wrapper.html()).toContain('Lingua')
  })

  it('forwards the buildLocaleOptions output to the v-select items prop', async () => {
    const harness = await mountAuthPage(AuthLayout)
    teardown = harness.unmount
    const select = harness.wrapper.findComponent({ name: 'VSelect' })
    expect(select.exists()).toBe(true)
    const items = select.props('items') as Array<{ value: string; title: string }>
    expect(new Set(items.map((i) => i.value))).toEqual(new Set(['en', 'pt', 'it']))
  })
})
