import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia } from 'pinia'
import { createVuetify } from 'vuetify'

import App from '@/App.vue'

describe('App.vue (admin)', () => {
  it('renders the admin title from i18n', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          app: {
            title: 'Catalyst Engine — Admin',
            subtitle: 'Super-admin console.',
            sprint0Notice: 'Sprint 0 scaffolding — admin SPA',
          },
        },
      },
    })

    const wrapper = mount(App, {
      global: {
        plugins: [createPinia(), i18n, createVuetify()],
      },
    })

    expect(wrapper.text()).toContain('Admin')
    expect(wrapper.text()).toContain('Super-admin console')
  })
})
