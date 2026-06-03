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

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import ThemeToggle from '@/components/ThemeToggle.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { buildLocaleOptions } from '@/modules/auth/layouts/localeOptions'

const { t, locale, availableLocales } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const { user, isLoggingOut } = storeToRefs(authStore)
const localeOptions = buildLocaleOptions(availableLocales, t)
const userMenuOpen = ref(false)

/**
 * Creator topbar nav (D-b13). Two router-linked items; active-state is
 * driven by vue-router's link matching on the current route (no manual
 * `route.name` checks). Localized via the `availability` bundle.
 */
const navItems = [
  { key: 'dashboard', icon: 'mdi-view-dashboard-outline', routeName: 'creator.dashboard' },
  { key: 'availability', icon: 'mdi-calendar-month-outline', routeName: 'creator.availability' },
] as const

async function signOut(): Promise<void> {
  userMenuOpen.value = false
  await authStore.logout()
  await router.push({ name: 'auth.sign-in' })
}
</script>

<template>
  <v-app data-test="creator-layout">
    <v-app-bar elevation="1" data-test="creator-topbar">
      <div class="d-flex align-center px-4">
        <v-icon icon="mdi-lightning-bolt" color="primary" size="small" class="mr-2" />
        <span class="text-subtitle-1 font-weight-bold" data-test="creator-brand">
          {{ t('app.title') }}
        </span>
      </div>

      <nav class="d-flex align-center ml-2 ml-sm-6" data-test="creator-nav" aria-label="Primary">
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

    <v-main data-test="creator-main">
      <v-container class="pa-6" style="max-width: 960px">
        <slot />
      </v-container>
    </v-main>
  </v-app>
</template>
