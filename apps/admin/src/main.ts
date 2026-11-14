import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import { resolveBootLocale } from './composables/useLocalePreference'
import { initSentry } from './core/observability/initSentry'
import { router } from './core/router'
import { i18n, setLocale } from './core/i18n'
import { vuetify } from './plugins/vuetify'

// Self-hosted Inter typeface (Catalyst Engine v2). Imported before the design
// tokens so the @font-face + .v-application family override are
// registered alongside the token CSS variables they consume.
import '@catalyst/ui/assets/fonts/inter.css'
import '@catalyst/design-tokens/tokens.css'

const app = createApp(App)

app.use(createPinia())

// AH-121 C2: Sentry (errors only). A no-op without VITE_SENTRY_DSN_ADMIN: no init, nothing sent.
// After Pinia (the user comes from the auth store), before the router is installed.
initSentry(app, router)
app.use(router)
app.use(i18n)
app.use(vuetify)

// Resolve and PRELOAD the target locale before the first paint so the UI
// never renders against a half-loaded (or English-fallback) bundle. The
// pre-auth target comes from localStorage (the user's last choice); once
// the authenticated user loads, the server `preferred_language` wins and
// the auth store re-flips the locale. `en` is the default when unset.
void setLocale(resolveBootLocale(i18n.global.locale.value)).then(() => {
  app.mount('#app')
})
