import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import { resolveBootLocale } from './composables/useLocalePreference'
import { SESSION_EXPIRED_QUERY_REASON } from './core/api'
import { router } from './core/router'
import { i18n, setLocale } from './core/i18n'
import { startAppResumeRefresh } from './core/platform/appResume'
import { bootNativeShell } from './core/platform/bootNativeShell'
import { createPushTapHandler } from './core/platform/push/pushTapRouting'
import { startPush } from './core/platform/push/startPush'
import { useAuthStore } from './modules/auth/stores/useAuthStore'
import { notificationsApi } from './modules/notifications/api/notifications.api'
import { requestNotificationsRefresh } from './modules/notifications/composables/notificationsRefresh'
import { createTapResolver } from './modules/notifications/pushTapResolver'
import { lookupThreadCounterparty } from './modules/notifications/threadLookup'
import { useAgencyStore } from './core/stores/useAgencyStore'
import { vuetify } from './plugins/vuetify'

// Self-hosted Inter typeface (Catalyst Engine v2). Imported before the design
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
// pre-auth target comes from localStorage (the user's last choice); once
// the authenticated user loads, the server `preferred_language` wins and
// the auth store re-flips the locale. `en` is the default when unset.
// Inside the creator app the native token layer loads alongside it.
void Promise.all([
  setLocale(resolveBootLocale(i18n.global.locale.value)),
  bootNativeShell(document),
]).then(() => {
  app.mount('#app')
  // AH-100d: creator app only (a no-op on the web) — re-read the session when
  // the app returns to the foreground. After mount so the stores exist and the
  // first bootstrap (the router guard) owns the cold start.
  startAppResumeRefresh({
    onSessionLapsed: () => {
      void router.push({ name: 'auth.sign-in', query: { reason: SESSION_EXPIRED_QUERY_REASON } })
    },
  })

  // AH-102: creator app only (a no-op on the web, and on iOS until AH-102-ios) —
  // push token upkeep and the tap / foreground handlers. It never asks for
  // permission (that is the soft-ask sheet's job). A foreground push re-reads the bell
  // and shows no banner. The router and the refresh are injected here because
  // only this wiring layer may touch them.
  //
  // AH-103: a tap opens the screen its notification is about — the payload's
  // route ids are parsed and resolved against the registry (the list when nothing
  // resolves), and the in-app row is marked read once the user has landed.
  const push = startPush({
    // Waits for the router's first navigation: a cold-start tap is delivered
    // before it, and an immediate push is overtaken (see pushTapRouting.ts).
    onTap: createPushTapHandler(router, {
      resolveTap: createTapResolver({
        hasRoute: (name) => router.hasRoute(name),
        hasSession: () => useAuthStore().user !== null,
        lookupThread: (side, threadUlid) =>
          lookupThreadCounterparty(side, threadUlid, useAgencyStore().currentAgencyId),
      }),
      hasSession: () => useAuthStore().user !== null,
      markRead: (ulid) => notificationsApi.markRead(ulid),
      onMarkedRead: () => requestNotificationsRefresh(),
    }),
    onForeground: () => requestNotificationsRefresh(),
  })
  // A session appearing (sign-in on a device that already granted permission)
  // registers without an app restart.
  watch(
    () => useAuthStore().user?.id ?? null,
    (userId) => {
      if (userId !== null) {
        push.sync()
      }
    },
  )
})
