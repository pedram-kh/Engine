/**
 * AH-114 walk harness (a dev tool, NOT part of the shipped app).
 *
 * Mounts the REAL `App.vue`, the REAL layouts, the REAL route table (guards
 * stripped, page components replaced by one deterministic probe page), the
 * REAL Vuetify plugin and the REAL token CSS, on a memory router. It exists so
 * `walk.mjs` can measure the shell and the Vuetify primitives in a real
 * browser with no API behind it.
 *
 * Query string:
 *   to=<path>       route to open (default /campaigns)
 *   user=agency|creator|none   fake signed-in user for the layout (default: agency)
 *   theme=light     start with the light theme (default: dark, as in the app)
 *
 * `window.__walk` is the contract with `walk.mjs`.
 */

import { createPinia } from 'pinia'
import { createApp } from 'vue'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'

import App from '@/App.vue'
import { i18n, setLocale } from '@/core/i18n'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { routes } from '@/modules/auth/routes'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { vuetify } from '@/plugins/vuetify'

import ProbePage from './ProbePage.vue'

import '@catalyst/ui/assets/fonts/inter.css'
import '@catalyst/design-tokens/tokens.css'

interface RouteInfo {
  path: string
  name: string
  layout: string
}

interface WalkApi {
  ready: boolean
  routes: RouteInfo[]
  push: (path: string) => Promise<void>
  errors: string[]
}

declare global {
  interface Window {
    __walk: WalkApi
  }
}

/** `?real=campaigns.list,campaigns.create` keeps those routes' real page components (the screenshot sets feed them fixtures). */
const realRoutes = new Set(
  (new URLSearchParams(location.search).get('real') ?? '').split(',').filter(Boolean),
)

function stub(list: RouteRecordRaw[]): RouteRecordRaw[] {
  return list.map((r) => {
    const out = { ...r } as Record<string, unknown>
    delete out.beforeEnter
    delete out.redirect
    if (!(typeof r.name === 'string' && realRoutes.has(r.name))) out.component = ProbePage
    if (r.meta) out.meta = { ...r.meta, guards: [] }
    if (r.children) out.children = stub(r.children)
    return out as unknown as RouteRecordRaw
  })
}

function flatten(list: RouteRecordRaw[], prefix = ''): RouteInfo[] {
  const out: RouteInfo[] = []
  for (const r of list) {
    const full = r.path.startsWith('/') ? r.path : `${prefix}/${r.path}`
    if (r.name && !r.children?.length && r.component) {
      out.push({
        path: full.replace(/:[A-Za-z]+(\([^)]*\))?\??/g, 'x'),
        name: String(r.name),
        layout: String(r.meta?.layout ?? 'app'),
      })
    }
    if (r.children) out.push(...flatten(r.children, full))
  }
  return out
}

const stubbed = stub(routes)
const router = createRouter({ history: createMemoryHistory(), routes: stubbed })

const walk: WalkApi = {
  ready: false,
  routes: flatten(stubbed),
  push: async (path: string) => {
    await router.push(path)
  },
  errors: [],
}
window.__walk = walk
window.addEventListener('error', (e) => walk.errors.push(`error: ${e.message}`))
window.addEventListener('unhandledrejection', (e) =>
  walk.errors.push(`reject: ${String(e.reason)}`),
)

const params = new URLSearchParams(location.search)
const start = params.get('to') ?? '/campaigns'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(i18n)
app.use(vuetify)

const kind = params.get('user')
const fakeUser = {
  id: '01HWALK000000000000000000',
  type: 'user' as const,
  attributes: {
    email: 'maya.lindqvist@northwind.example',
    email_verified_at: '2026-01-01T00:00:00Z',
    name: 'Maya',
    last_name: 'Lindqvist',
    user_type: kind === 'creator' ? 'creator' : 'agency_user',
    preferred_language: 'en',
    preferred_currency: null,
    timezone: 'Europe/Madrid',
    theme_preference: 'dark',
    mfa_required: false,
    two_factor_enabled: true,
    mfa_exempt: false,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
  relationships: {
    agency_memberships: {
      data:
        kind === 'creator'
          ? []
          : [
              {
                agency_id: '01HWALKAGENCY000000000000',
                agency_name: 'Northwind',
                role: 'agency_admin' as const,
              },
            ],
    },
  },
}

// `theme=light` mirrors a user whose stored preference is light.
if (params.get('theme') === 'light') vuetify.theme.change('light')

if (kind !== 'none') {
  const auth = useAuthStore(pinia)
  auth.user = fakeUser as never
  useAgencyStore(pinia).initFromUser(fakeUser.relationships.agency_memberships.data)
}

// Navigate BEFORE installing the router so its own initial push cannot race ours.
void Promise.all([setLocale('en'), router.push(start)]).then(() => {
  app.use(router)
  app.mount('#app')
  walk.ready = true
})
