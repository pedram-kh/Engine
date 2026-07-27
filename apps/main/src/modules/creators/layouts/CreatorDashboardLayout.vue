<script setup lang="ts">
/**
 * CreatorDashboardLayout — minimal creator-facing shell for the
 * post-submit surface (Sprint 3 Chunk 3 sub-step 2 scaffold,
 * fleshed out in sub-step 8).
 *
 * Structure:
 *   v-app-bar with brand + locale switcher + user menu (sign-out).
 *   v-main with slot.
 *
 * Distinct from `AgencyLayout`: TOPBAR nav (not a sidebar). The creator
 * surface is thin (2 items), so primary nav rides the existing app bar as
 * router-linked buttons (Sprint 5 Chunk B, D-b13) — a sidebar would read
 * heavy/empty here, and extending the topbar is lower-risk than adding a
 * new structural region. No workspace switcher (creator is global).
 */

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useDisplay } from 'vuetify'

import ThemeToggle from '@/components/ThemeToggle.vue'
import ImpersonationBanner from '@/modules/impersonation/components/ImpersonationBanner.vue'
import NotificationBell from '@/modules/notifications/components/NotificationBell.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { useOnboardingStore } from '@/modules/onboarding/stores/useOnboardingStore'
import { buildLocaleOptions } from '@/modules/auth/layouts/localeOptions'
import { useLocaleSwitch } from '@/core/i18n/useLocaleSwitch'
import { useTheme } from '@/composables/useTheme'
import catalystLogo from '@/modules/auth/assets/catalyst-logo.svg'

const { t, locale } = useI18n()
const { selectLocale } = useLocaleSwitch()
const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const onboardingStore = useOnboardingStore()
const display = useDisplay()
const { currentTheme } = useTheme()
const { user, isLoggingOut } = storeToRefs(authStore)
const { applicationStatus } = storeToRefs(onboardingStore)
const localeOptions = buildLocaleOptions()
const userMenuOpen = ref(false)

/**
 * The logo's wordmark is near-white and vanishes on the light header, so we
 * render it solid-dark in light mode. This MUST be a theme-driven class on the
 * <img> itself — NOT a `:global(.v-theme--light) … { filter }` scoped rule:
 * Vue's scoped compiler collapses a leading `:global()` + descendant down to
 * just `.v-theme--light { filter: brightness(0) }`, which blacks out the whole
 * app root in light mode (regression fixed here).
 */
const logoNeedsDarkening = computed(() => currentTheme.value === 'light')

/**
 * AH-013 — pages opt into a full-width content area via `meta.wide` (the
 * messaging two-pane). Everything else keeps the comfortable 960px reading
 * column. `route.meta` merges parent → child, so the flag holds while a
 * conversation is open in the nested thread route.
 */
const wideContent = computed(() => route.meta.wide === true)

/**
 * Mobile chrome (smAndDown): the primary topbar nav overflows the cramped
 * mobile app-bar, so it moves to a thumb-reachable bottom navigation bar.
 * Utility actions stay in the topbar avatar menu. Desktop is untouched.
 */
const isMobile = computed(() => display.smAndDown.value)

/**
 * Creator topbar nav (D-b13). Router-linked items; active-state is driven by
 * vue-router's link matching on the current route (no manual `route.name`
 * checks). Localized via the `availability` bundle. Rendered identically in
 * the desktop topbar and the AH-007 mobile bottom-nav.
 *
 * AH-009: the "Profile" editor is shown to post-submission creators only
 * (pending / approved / rejected). Incomplete creators are still in the wizard
 * — their self-edit path is the wizard itself — so the item is hidden for them
 * to avoid two competing edit surfaces. `applicationStatus` is null until the
 * onboarding store bootstraps (driven by the landed page); the computed picks
 * the item up reactively once it resolves.
 */
const navItems = computed(() => {
  const items: { key: string; icon: string; routeName: string }[] = [
    { key: 'dashboard', icon: 'mdi-view-dashboard-outline', routeName: 'creator.dashboard' },
  ]
  if (applicationStatus.value !== null && applicationStatus.value !== 'incomplete') {
    items.push({
      key: 'profile',
      icon: 'mdi-account-circle-outline',
      routeName: 'creator.profile',
    })
  }
  if (applicationStatus.value === 'approved') {
    // AH-056 (D9) — the jobs board. Shown to APPROVED creators only: an
    // unapproved creator's board is empty by the server predicate, and an
    // always-visible item that always leads to an empty state is a worse
    // answer than no item. The server, not this branch, is the boundary.
    items.push({ key: 'jobs', icon: 'mdi-briefcase-search-outline', routeName: 'creator.jobs' })
  }
  items.push(
    { key: 'assignments', icon: 'mdi-clipboard-text-outline', routeName: 'creator.assignments' },
    { key: 'availability', icon: 'mdi-calendar-month-outline', routeName: 'creator.availability' },
    // AH-010b — top-level relationship-messaging inbox (D9). Rendered in both the
    // desktop topbar and the AH-007 mobile bottom-nav off this same array.
    { key: 'messages', icon: 'mdi-message-text-outline', routeName: 'creator.messages' },
  )
  return items
})

/**
 * AH-057 — the mobile bottom bar's PRIMARY slots, in bar order.
 *
 * A bottom bar neither wraps nor scrolls: every item it is handed competes for
 * the same fixed row, so past four the labels clip at the viewport edge (found
 * on a real phone once AH-056's "Job Posts" made a sixth item). The fix is the
 * standard overflow: four primary destinations plus a "More" sheet holding the
 * rest, which means the bar's width no longer depends on how many sections the
 * creator shell has.
 *
 * This is a declared POLICY, not a slice — the four are the working loop (find
 * a job, answer an invite, read a message, land on the dashboard). Profile and
 * Availability are deliberate overflow: both are settings-shaped, visited
 * occasionally, and neither is where a creator returns daily. `navItems` stays
 * the single ordered source for BOTH shells; this only decides which of its
 * entries get a permanent slot.
 *
 * Adding a nav item does NOT require touching this list — the newcomer lands in
 * More, which is the safe default. Promoting it is then a deliberate edit here,
 * and the spec pins that every key in this list actually exists in `navItems`,
 * so a rename cannot silently empty a slot.
 */
const MOBILE_PRIMARY_KEYS = ['dashboard', 'jobs', 'assignments', 'messages'] as const

const mobilePrimaryItems = computed(() =>
  MOBILE_PRIMARY_KEYS.map((key) => navItems.value.find((item) => item.key === key)).filter(
    (item): item is { key: string; icon: string; routeName: string } => item !== undefined,
  ),
)

const mobileOverflowItems = computed(() =>
  navItems.value.filter((item) => !(MOBILE_PRIMARY_KEYS as readonly string[]).includes(item.key)),
)

const moreMenuOpen = ref(false)

async function signOut(): Promise<void> {
  userMenuOpen.value = false
  await authStore.logout()
  await router.push({ name: 'auth.sign-in' })
}

/**
 * Bootstrap the onboarding store once at the shell level so `applicationStatus`
 * (and thus the conditional "Profile" nav item) resolves no matter which creator
 * page the user lands on or refreshes — previously only the Dashboard/Profile
 * pages bootstrapped, so a deep-link/refresh onto Messages/Availability left the
 * nav missing Profile. The `isBootstrapped` guard + the store's in-flight dedupe
 * make this a no-op when a page (or a prior visit) already fetched it.
 */
onMounted(() => {
  if (!onboardingStore.isBootstrapped) {
    void onboardingStore.bootstrap()
  }
})
</script>

<template>
  <v-app data-test="creator-layout">
    <ImpersonationBanner />

    <v-app-bar elevation="1" data-test="creator-topbar">
      <div class="d-flex align-center px-4" data-test="creator-brand">
        <img
          :src="catalystLogo"
          alt="Catalyst"
          class="creator-topbar__logo"
          :class="{ 'creator-topbar__logo--on-light': logoNeedsDarkening }"
        />
      </div>

      <!-- Desktop: inline topbar nav. On mobile this moves to a bottom bar. -->
      <nav
        v-if="!isMobile"
        class="d-flex align-center ml-2 ml-sm-6"
        data-test="creator-nav"
        aria-label="Primary"
      >
        <v-btn
          v-for="item in navItems"
          :key="item.key"
          :to="{ name: item.routeName }"
          :prepend-icon="item.icon"
          variant="text"
          class="text-none"
          :data-test="`creator-nav-${item.key}`"
        >
          {{ t(`availability.creatorNav.${item.key}`) }}
        </v-btn>
      </nav>

      <v-spacer />

      <!-- Notification bell + unread badge + recent-slice dropdown (S11.0 Ch3a) -->
      <NotificationBell view-all-route="creator.notifications" class="mr-1" />

      <v-menu
        v-model="userMenuOpen"
        offset-y
        :close-on-content-click="false"
        data-test="user-menu-trigger-wrapper"
      >
        <template #activator="{ props: menuProps }">
          <v-btn v-bind="menuProps" variant="text" class="text-none" data-test="user-menu-btn">
            <v-avatar size="32" color="primary" class="mr-2">
              <span class="text-caption font-weight-bold text-white">
                {{ (user?.attributes.name ?? '?')[0]?.toUpperCase() }}
              </span>
            </v-avatar>
            <span class="text-body-2 d-none d-sm-inline" data-test="user-menu-name">
              {{ user?.attributes.name ?? '' }}
            </span>
            <v-icon icon="mdi-chevron-down" size="small" class="ml-1" />
          </v-btn>
        </template>

        <v-card min-width="240" data-test="user-menu">
          <v-card-text class="pb-0">
            <p class="text-caption text-medium-emphasis mb-2">
              {{ user?.attributes.email ?? '' }}
            </p>

            <div class="d-flex align-center justify-space-between mb-3">
              <span class="text-body-2">{{ t('app.theme.toggle.label') }}</span>
              <ThemeToggle />
            </div>

            <v-select
              :model-value="locale"
              :items="localeOptions"
              :label="t('app.locale.switcher')"
              item-title="title"
              item-value="value"
              density="compact"
              variant="outlined"
              hide-details
              class="mb-3"
              data-test="user-menu-locale-switcher"
              @update:model-value="selectLocale"
            />
          </v-card-text>

          <v-divider />

          <v-list density="compact">
            <v-list-item
              :to="{ name: 'creator.notifications.preferences' }"
              :title="t('notifications.preferences.menuItem')"
              prepend-icon="mdi-bell-cog-outline"
              data-test="notification-settings-link"
              @click="userMenuOpen = false"
            />
            <v-list-item
              :disabled="isLoggingOut"
              :title="isLoggingOut ? t('app.userMenu.signingOut') : t('app.userMenu.signOut')"
              prepend-icon="mdi-logout"
              data-test="sign-out-btn"
              @click="signOut"
            />
          </v-list>
        </v-card>
      </v-menu>
    </v-app-bar>

    <v-main data-test="creator-main">
      <v-container
        :class="wideContent ? 'pa-2 pa-md-6' : 'pa-6'"
        :fluid="wideContent"
        :style="wideContent ? undefined : 'max-width: 960px'"
      >
        <slot />
      </v-container>
    </v-main>

    <!-- Mobile: primary nav as a thumb-reachable bottom bar (smAndDown only).
         Active state is router-driven, mirroring the desktop topbar nav.
         Four primary slots + a "More" sheet (AH-057) — the bar cannot scroll,
         so its content must not depend on how many sections exist. -->
    <v-bottom-navigation
      v-if="isMobile"
      grow
      color="primary"
      class="creator-bottom-nav"
      data-test="creator-bottom-nav"
    >
      <v-btn
        v-for="item in mobilePrimaryItems"
        :key="item.key"
        :to="{ name: item.routeName }"
        :data-test="`creator-bottom-nav-${item.key}`"
      >
        <v-icon :icon="item.icon" />
        <span class="creator-bottom-nav__label">
          {{ t(`availability.creatorNav.${item.key}`) }}
        </span>
      </v-btn>

      <v-menu
        v-if="mobileOverflowItems.length > 0"
        v-model="moreMenuOpen"
        location="top end"
        data-test="creator-bottom-nav-more-wrapper"
      >
        <template #activator="{ props: moreProps }">
          <v-btn v-bind="moreProps" data-test="creator-bottom-nav-more">
            <v-icon icon="mdi-dots-horizontal" />
            <span class="creator-bottom-nav__label">
              {{ t('availability.creatorNav.more') }}
            </span>
          </v-btn>
        </template>

        <v-list density="compact" data-test="creator-bottom-nav-more-list">
          <v-list-item
            v-for="item in mobileOverflowItems"
            :key="item.key"
            :to="{ name: item.routeName }"
            :prepend-icon="item.icon"
            :title="t(`availability.creatorNav.${item.key}`)"
            :data-test="`creator-bottom-nav-more-${item.key}`"
            @click="moreMenuOpen = false"
          />
        </v-list>
      </v-menu>
    </v-bottom-navigation>
  </v-app>
</template>

<style scoped>
/* AH-057 — four slots stop the bar OVERFLOWING; this stops a single long label
   from bursting its own slot. English is the short case: the same keys run to
   "Табло за управление" (bg) and "Postijiet tax-xogħol" (mt), which are wider
   than any quarter of a phone viewport. Ellipsis rather than wrap keeps every
   button one line tall, so the bar's height never depends on the locale.

   The selector mirrors Vuetify's own
   (`.v-bottom-navigation .v-bottom-navigation__content > .v-btn`, which sets
   `min-width: 80px`) because a shorter one TIES on specificity and loses on
   order: five 80px floors make a 400px row inside a 390px viewport, so the bar
   overhangs 5px each side and scrolls. Caught by the E2E leg measuring it, and
   the leg now holds the bar to zero horizontal overflow. */
.creator-bottom-nav :deep(.v-bottom-navigation__content > .v-btn) {
  min-width: 0;
}

.creator-bottom-nav__label {
  display: block;
  max-width: 100%;
  overflow: hidden;
  font-size: 0.625rem;
  line-height: 1.2;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.creator-topbar__logo {
  display: block;
  height: 28px;
}

/* Light-mode only: the near-white wordmark is invisible on the light header,
 * so render it solid dark. Toggled by `logoNeedsDarkening` (theme-driven class)
 * rather than an ancestor `:global(.v-theme--light)` selector — see the script
 * comment for why that scoped-CSS pattern blacked out the whole app. */
.creator-topbar__logo--on-light {
  filter: brightness(0);
}
</style>
