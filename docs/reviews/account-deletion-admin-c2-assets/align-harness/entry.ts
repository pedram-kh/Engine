import { createApp, h } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { VApp } from 'vuetify/components/VApp'
import { i18n, setLocale } from '@/core/i18n'
import { vuetify } from '@/plugins/vuetify'
import '@catalyst/ui/assets/fonts/inter.css'
import '@catalyst/design-tokens/tokens.css'
import DeleteAccountDialog from '@/modules/creators/components/DeleteAccountDialog.vue'

const router = createRouter({
  history: createMemoryHistory(),
  routes: [{ path: '/', component: { render: () => h('div') } }],
})
const app = createApp({
  render: () => h(VApp, null, () => h(DeleteAccountDialog, { modelValue: true })),
})
app.use(createPinia())
app.use(router)
app.use(i18n)
app.use(vuetify)
const web = location.search.includes('web')
if (!web) {
  await import('@catalyst/design-tokens/native.css')
  document.documentElement.classList.add('catalyst-native', 'platform-ios')
}
void setLocale('en').then(() => app.mount('#app'))
