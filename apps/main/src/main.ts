import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import { router } from './core/router'
import { i18n, setLocale } from './core/i18n'
import { vuetify } from './plugins/vuetify'

// Self-hosted Inter typeface (Engine C v2). Imported before the design
// tokens so the @font-face + .v-application family override are
// registered alongside the token CSS variables they consume.
import '@catalyst/ui/assets/fonts/inter.css'
import '@catalyst/design-tokens/tokens.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)
app.use(vuetify)

// Resolve and PRELOAD the target locale before the first paint so the UI
// never renders against a half-loaded (or English-fallback) bundle. The
// target is `en` today; S5 resolves it from persistence here instead.
void setLocale(i18n.global.locale.value).then(() => {
  app.mount('#app')
})
