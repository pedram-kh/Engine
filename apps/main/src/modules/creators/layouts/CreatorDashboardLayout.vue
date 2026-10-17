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
 *
 * AH-100: inside the Capacitor creator app this is the app shell (Figma
 * frames 07/08) — the bottom tab bar at every width, the Figma app-bar
 * and tab styling, safe-area padding, a "Settings" entry and per-item
 * subtitles in the More menu, and no theme toggle (the app is dark-only
 * in v1). Every native difference is gated on `isNative`; the web shell
 * renders exactly as before.
 */

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import ThemeToggle from '@/components/ThemeToggle.vue'
import ImpersonationBanner from '@/modules/impersonation/components/ImpersonationBanner.vue'
import NotificationBell from '@/modules/notifications/components/NotificationBell.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { useOnboardingStore } from '@/modules/onboarding/stores/useOnboardingStore'
import { buildLocaleOptions } from '@/modules/auth/layouts/localeOptions'
import { useLocaleSwitch } from '@/core/i18n/useLocaleSwitch'
import { useMobileShell } from '@/composables/useMobileShell'
import { useShellBack } from '@/composables/useShellBack'
import { useTheme } from '@/composables/useTheme'
import catalystLogo from '@/modules/auth/assets/catalyst-logo.svg'

const { t, te, locale } = useI18n()
const { selectLocale } = useLocaleSwitch()
const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const onboardingStore = useOnboardingStore()
const { isNative, isMobile } = useMobileShell()
const { visible: showBack, goBack } = useShellBack()
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
 * Mobile chrome (smAndDown, or always inside the app — `useMobileShell`):
 * the primary topbar nav overflows the cramped mobile app-bar, so it moves
 * to a thumb-reachable bottom navigation bar. Utility actions stay in the
 * topbar avatar menu. Desktop is untouched.
 */

/** AH-100 — the app's More-menu subtitle for an item, when one is authored. */
function moreSubtitle(key: string): string | undefined {
  const subtitleKey = `availability.creatorNav.subtitles.${key}`
  return isNative && te(subtitleKey) ? t(subtitleKey) : undefined
}

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
  <v-app :class="{ 'creator-layout--native': isNative }" data-test="creator-layout">
    <ImpersonationBanner />

    <v-app-bar
      elevation="1"
      :class="{ 'creator-topbar--native': isNative }"
      data-test="creator-topbar"
    >
      <!-- AH-106 — the shell's back arrow, on every page that is not a tab and
           does not draw its own back control (see `useShellBack`). -->
      <v-btn
        v-if="showBack"
        icon="mdi-arrow-left"
        variant="text"
        density="comfortable"
        class="ml-2"
        :aria-label="t('creator.ui.wizard.actions.back')"
        data-test="creator-shell-back"
        @click="goBack"
      />

      <div
        class="d-flex align-center"
        :class="showBack ? 'pl-2 pr-4' : 'px-4'"
        data-test="creator-brand"
      >
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
            <v-avatar size="32" :color="isNative ? 'primary-container' : 'primary'" class="mr-2">
              <span
                class="text-caption font-weight-bold"
                :class="isNative ? 'text-primary' : 'text-white'"
              >
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

            <div v-if="!isNative" class="d-flex align-center justify-space-between mb-3">
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

    <v-main :class="{ 'creator-main--native': isNative }" data-test="creator-main">
      <v-container
        :class="wideContent ? 'pa-2 pa-md-6' : isNative ? 'px-4 py-6' : 'pa-6'"
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
      :class="{ 'creator-bottom-nav--native': isNative }"
      data-test="creator-bottom-nav"
    >
      <v-btn
        v-for="item in mobilePrimaryItems"
        :key="item.key"
        :to="{ name: item.routeName }"
        :data-test="`creator-bottom-nav-${item.key}`"
      >
        <span v-if="isNative" class="creator-bottom-nav__line" aria-hidden="true" />
        <v-icon :icon="item.icon" />
        <span class="creator-bottom-nav__label">
          {{ t(`availability.creatorNav.${isNative ? 'tab.' : ''}${item.key}`) }}
        </span>
      </v-btn>

      <v-menu
        v-if="mobileOverflowItems.length > 0"
        v-model="moreMenuOpen"
        location="top end"
        :scrim="isNative"
        data-test="creator-bottom-nav-more-wrapper"
      >
        <template #activator="{ props: moreProps }">
          <v-btn
            v-bind="moreProps"
            :class="{ 'creator-bottom-nav__more--open': isNative && moreMenuOpen }"
            data-test="creator-bottom-nav-more"
          >
            <span v-if="isNative" class="creator-bottom-nav__line" aria-hidden="true" />
            <v-icon icon="mdi-dots-horizontal" />
            <span class="creator-bottom-nav__label">
              {{
                t(isNative ? 'availability.creatorNav.tab.more' : 'availability.creatorNav.more')
              }}
            </span>
          </v-btn>
        </template>

        <v-list
          density="compact"
          :class="{ 'creator-more-menu--native': isNative }"
          data-test="creator-bottom-nav-more-list"
        >
          <v-list-item
            v-for="item in mobileOverflowItems"
            :key="item.key"
            :to="{ name: item.routeName }"
            :prepend-icon="item.icon"
            :title="t(`availability.creatorNav.${item.key}`)"
            :subtitle="moreSubtitle(item.key)"
            :data-test="`creator-bottom-nav-more-${item.key}`"
            @click="moreMenuOpen = false"
          />
          <!-- AH-100 Q7: the app's "Settings" is notification preferences in v1
               (the web reaches them from the avatar menu). -->
          <v-list-item
            v-if="isNative"
            :to="{ name: 'creator.notifications.preferences' }"
            prepend-icon="mdi-cog-outline"
            :title="t('availability.creatorNav.settings')"
            :subtitle="moreSubtitle('settings')"
            data-test="creator-bottom-nav-more-settings"
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

/* AH-100 — the creator app shell (Figma frames 07/08). Every rule hangs off
   a `--native` modifier that is only bound inside the Capacitor app, and
   reads the `--native-*` tokens from @catalyst/design-tokens/native.css.
   The fixed app bar and tab bar grow by the notch / home-indicator insets,
   and <v-main> reserves the same extra room so content is never covered. */
.creator-topbar--native {
  background: var(--native-bg-topbar) !important;
  border-bottom: 1px solid var(--native-border-default);
  box-shadow: none !important;
  padding-top: var(--native-safe-top);
}

.creator-main--native {
  padding-top: calc(var(--v-layout-top) + var(--native-safe-top));
  padding-bottom: calc(var(--v-layout-bottom) + var(--native-safe-bottom));
}

.creator-bottom-nav--native {
  background: var(--native-bg-sidebar) !important;
  border-top: 1px solid var(--native-border-default);
  box-shadow: none !important;
  /* Frame 07's bar: 1px rule + 58px item row + the home-indicator inset. */
  height: calc(59px + var(--native-safe-bottom)) !important;
  padding-bottom: var(--native-safe-bottom);
}

/* The selected treatment, from frames 07 / 09 / 10 (Bottom nav, node 20:296):
   NO fill and NO block. A tab is an icon over a label. The selected one has its
   icon and label in the accent colour, the label in Medium (Caption/Strong), and a
   28 x 2 accent line (radius/full) at the very top of its cell. Unselected tabs are
   text/tertiary, Regular. Items share the row equally; gap 4, icon 22, label 12/16.

   Why it read as "a filled rounded block": `.catalyst-native .v-btn` gives every
   button the 8px radius (!important, AH-100d), and Vuetify paints an overlay on
   the active button, so the active tab became a rounded tinted block. The bar's
   buttons take the square cell back, and the overlay and underlay are not drawn. */
.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn) {
  position: relative;
  flex: 1 1 0;
  min-width: 0;
  max-width: none;
  padding: 0 2px;
  color: var(--native-text-tertiary);
  letter-spacing: 0;
  text-transform: none;
  background: transparent;
  border-radius: 0 !important;
}

.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn .v-btn__overlay),
.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn .v-btn__underlay) {
  display: none;
}

.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn .v-btn__content) {
  gap: 4px;
  max-width: 100%;
}

.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn .v-icon) {
  --v-icon-size-multiplier: 1;

  width: 22px;
  height: 22px;
  font-size: 22px;
}

.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn:focus-visible) {
  outline: 2px solid var(--native-accent-primary);
  outline-offset: -2px;
}

.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn--active),
.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .creator-bottom-nav__more--open) {
  color: var(--native-accent-primary);
}

/* The selected line is a real element, not a pseudo-element: Vuetify's own
   `.v-btn::before` / `::after` carry the hover and focus layers, and its `::after`
   is `opacity: 0`, so the line drawn there since AH-100 never showed. */
.creator-bottom-nav__line {
  position: absolute;
  top: 0;
  left: 50%;
  display: none;
  width: 28px;
  height: 2px;
  margin-left: -14px;
  background: var(--native-accent-primary);
  border-radius: var(--native-radius-full);
}

.creator-bottom-nav--native :deep(.v-btn--active) .creator-bottom-nav__line,
.creator-bottom-nav--native .creator-bottom-nav__more--open .creator-bottom-nav__line {
  display: block;
}

.creator-bottom-nav--native .creator-bottom-nav__label {
  font: var(--native-type-caption);
}

.creator-bottom-nav--native :deep(.v-btn--active) .creator-bottom-nav__label,
.creator-bottom-nav--native .creator-bottom-nav__more--open .creator-bottom-nav__label {
  font: var(--native-type-caption-strong);
}

.creator-more-menu--native {
  width: 220px;
  padding: 6px 0;
  background: var(--native-bg-surface-raised) !important;
  border: 1px solid var(--native-border-strong);
  border-radius: var(--native-radius-xl) !important;
  box-shadow: var(--native-shadow-overlay) !important;
}

.creator-more-menu--native :deep(.v-list-item) {
  min-height: 0;
  padding: 10px 14px;
}

.creator-more-menu--native :deep(.v-list-item-title) {
  font: var(--native-type-body-strong);
  color: var(--native-text-primary);
}

.creator-more-menu--native :deep(.v-list-item-subtitle) {
  font: var(--native-type-caption);
  color: var(--native-text-tertiary);
  opacity: 1;
}
</style>

<style scoped src="./creatorNative.css"></style>
<style scoped src="./creatorProfileFormNative.css"></style>
<style scoped src="./creatorDashboardNative.css"></style>
