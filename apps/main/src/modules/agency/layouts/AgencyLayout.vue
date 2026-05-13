<script setup lang="ts">
/**
 * AgencyLayout — the primary authenticated shell for agency users.
 *
 * Structure:
 *   v-navigation-drawer (left, fixed, 260px)
 *     - Workspace name at top
 *     - Nav items: Dashboard, Brands, Team, Settings
 *   v-app-bar (top, fixed)
 *     - Workspace switcher (hidden when user has exactly one membership — Q2)
 *     - Spacer
 *     - User menu (avatar + name + dropdown: ThemeToggle, locale switcher, sign out)
 *   v-main
 *     - <slot /> (page content)
 *
 * Workspace switcher: Q2 answer = Option B (hidden when single membership).
 * Component is coded with the full multi-membership data structure from day one
 * so Sprint 3's multi-workspace unlock requires zero structural refactoring.
 *
 * Theme + locale: both migrate here from AuthLayout.vue / App.vue. The
 * <ThemeToggle /> and locale v-select live ONLY inside the user menu dropdown.
 *
 * Sign-out: wires to useAuthStore.logout + redirects to /sign-in.
 */

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import ThemeToggle from '@/components/ThemeToggle.vue'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { buildLocaleOptions } from '@/modules/auth/layouts/localeOptions'

const { t, locale, availableLocales } = useI18n()
const router = useRouter()
const agencyStore = useAgencyStore()
const authStore = useAuthStore()

const { memberships, currentAgencyId, currentAgencyName } = storeToRefs(agencyStore)
const { user, isLoggingOut } = storeToRefs(authStore)

const localeOptions = buildLocaleOptions(availableLocales, t)

const drawer = ref(true)
const userMenuOpen = ref(false)

const navItems = [
  { key: 'dashboard', icon: 'mdi-view-dashboard-outline', routeName: 'app.dashboard' },
  { key: 'brands', icon: 'mdi-tag-outline', routeName: 'brands.list' },
  { key: 'agencyUsers', icon: 'mdi-account-group-outline', routeName: 'agency-users.list' },
  { key: 'settings', icon: 'mdi-cog-outline', routeName: 'settings' },
]

async function signOut(): Promise<void> {
  userMenuOpen.value = false
  await authStore.logout()
  await router.push({ name: 'auth.sign-in' })
}

function onSwitchAgency(agencyId: string): void {
  agencyStore.switchAgency(agencyId)
  // Reload the current page so tenant-scoped data refreshes.
  router.go(0)
}
</script>

<template>
  <v-app data-test="agency-layout">
    <!-- ─── Navigation drawer ─────────────────────────────────── -->
    <v-navigation-drawer v-model="drawer" permanent width="260" data-test="agency-sidebar">
      <!-- Workspace name -->
      <div class="px-4 py-4 d-flex align-center ga-2" data-test="sidebar-workspace-name">
        <v-icon icon="mdi-lightning-bolt" color="primary" size="small" />
        <span class="text-subtitle-2 font-weight-semibold text-truncate">
          {{ currentAgencyName || t('app.title') }}
        </span>
      </div>

      <v-divider />

      <!-- Nav items -->
      <v-list nav density="compact" class="mt-2">
        <v-list-item
          v-for="item in navItems"
          :key="item.key"
          :to="{ name: item.routeName }"
          :prepend-icon="item.icon"
          :title="t(`app.nav.${item.key}`)"
          :data-test="`nav-${item.key}`"
          rounded="lg"
          active-color="primary"
        />
      </v-list>
    </v-navigation-drawer>

    <!-- ─── Top bar ────────────────────────────────────────────── -->
    <v-app-bar elevation="1" data-test="agency-topbar">
      <v-app-bar-nav-icon @click="drawer = !drawer" />

      <!-- Workspace switcher — hidden when user has only one membership (Q2: Option B) -->
      <v-select
        v-if="memberships.length > 1"
        :model-value="currentAgencyId"
        :items="memberships"
        item-title="agency_name"
        item-value="agency_id"
        density="compact"
        variant="outlined"
        hide-details
        :label="t('app.workspaceSwitcher.label')"
        class="agency-topbar__switcher mx-3"
        data-test="workspace-switcher"
        @update:model-value="onSwitchAgency"
      />

      <v-spacer />

      <!-- User menu -->
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

            <!-- Theme toggle -->
            <div class="d-flex align-center justify-space-between mb-3">
              <span class="text-body-2">{{ t('app.theme.toggle.label') }}</span>
              <ThemeToggle />
            </div>

            <!-- Locale switcher -->
            <v-select
              v-model="locale"
              :items="localeOptions"
              :label="t('app.locale.switcher')"
              item-title="title"
              item-value="value"
              density="compact"
              variant="outlined"
              hide-details
              class="mb-3"
              data-test="user-menu-locale-switcher"
            />
          </v-card-text>

          <v-divider />

          <v-list density="compact">
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

    <!-- ─── Main content ──────────────────────────────────────── -->
    <v-main data-test="agency-main">
      <v-container fluid class="pa-6">
        <slot />
      </v-container>
    </v-main>
  </v-app>
</template>

<style scoped>
.agency-topbar__switcher {
  max-width: 220px;
}
</style>
