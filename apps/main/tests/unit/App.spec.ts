import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia } from 'pinia'
import { createVuetify } from 'vuetify'

import App from '@/App.vue'

describe('App.vue', () => {
  it('renders the app title from i18n', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          app: {
            title: 'Catalyst Engine',
            subtitle: 'Influencer marketing, run like a real business.',
            sprint0Notice: 'Sprint 0 scaffolding — main platform SPA',
          },
        },
      },
    })

    const wrapper = mount(App, {
      global: {
        plugins: [createPinia(), i18n, createVuetify()],
      },
    })

    expect(wrapper.text()).toContain('Catalyst Engine')
    expect(wrapper.text()).toContain('run like a real business')
  })
})
