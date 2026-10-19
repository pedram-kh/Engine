import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import { resolveBootLocale } from './composables/useLocalePreference'
import { sessionExpiredLocation } from './core/api'
import { router } from './core/router'
import { i18n, setLocale } from './core/i18n'
import { startAppResumeRefresh } from './core/platform/appResume'
import { bootNativeShell } from './core/platform/bootNativeShell'
import { createAppLinkHandler } from './core/platform/links/appLinkRouting'
import { startAppLinks } from './core/platform/links/startAppLinks'
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
      void router.push(sessionExpiredLocation(router.currentRoute.value))
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
  startPush({
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
    // A session appearing (sign-in on a device that already granted permission)
    // registers without an app restart and without trusting the stored
    // registration time — startPush owns that watch, so a spec can drive it.
    userId: () => useAuthStore().user?.id ?? null,
  })

  // AH-103b: creator app only (a no-op on the web) — an https creator link opened
  // from an email, Notes or Messages opens the screen it names (the dashboard when
  // the link is ours but unusable; nothing when it is not ours). The handler waits
  // for the router like the push tap does, and a signed-out tap returns to the
  // screen after sign-in through the normal `redirect=` flow.
  startAppLinks({ onLink: createAppLinkHandler(router) })
})
