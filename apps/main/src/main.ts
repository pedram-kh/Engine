import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import { router } from './core/router'
import { i18n } from './core/i18n'
import { vuetify } from './plugins/vuetify'

import '@catalyst/design-tokens/tokens.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)
app.use(vuetify)

app.mount('#app')
