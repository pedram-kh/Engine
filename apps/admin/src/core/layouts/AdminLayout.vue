<script setup lang="ts">
/**
 * AdminLayout — the authenticated shell for the admin console
 * (Sprint 13, D-1). Everything post-auth mounts inside it.
 *
 * Mirrors `apps/main/src/modules/agency/layouts/AgencyLayout.vue`
 * structure (the shell precedent) with admin-specific density + chrome:
 *
 *   v-system-bar  → persistent env banner (LOCAL/STAGING/PRODUCTION, D-2)
 *   v-navigation-drawer (left, permanent, 280px — never collapses, § 5.1;
 *     widened from 220px so expanded group sub-items don't truncate)
 *     - Catalyst Admin wordmark
 *     - Declarative nav (NAV_ENTRIES) with groups + badge counts
 *   v-app-bar (top, 48px — denser than main's 56px, § 5.1)
 *     - spacer + user menu (email, ThemeToggle, locale, sign out)
 *   v-main → routed page
 *
 * This file OWNS the `<v-app>` (mirror of AgencyLayout); `App.vue`'s
 * layout switcher mounts it for `meta.layout === 'admin'` and MUST NOT
 * wrap it in a second `<v-app>` (Vuetify warns + the inner app's theme
 * tokens never resolve).
 */

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import ThemeToggle from '@/components/ThemeToggle.vue'
import { useIdleTimeout } from '@/modules/auth/composables/useIdleTimeout'
import { buildLocaleOptions } from '@/modules/auth/layouts/localeOptions'
import { useAdminAuthStore } from '@/modules/auth/stores/useAdminAuthStore'
import { useDeployEnv } from '@/core/composables/useDeployEnv'
import { useNavBadges } from '@/core/stores/useNavBadges'
import { NAV_ENTRIES, isNavGroup, type NavBadgeKey, type NavLeaf } from '@/core/nav/navItems'

/** Groups start expanded — the Phase-1 sidebar never collapses (§ 5.1)
 * and the admin needs every surface one glance away. */
const openedGroups = ref<string[]>(NAV_ENTRIES.filter(isNavGroup).map((g) => g.key))

const { t, locale, availableLocales } = useI18n()
const router = useRouter()
const authStore = useAdminAuthStore()

const { user, isLoggingOut } = storeToRefs(authStore)
const navBadges = useNavBadges()
const { creatorApprovals, kycQueue } = storeToRefs(navBadges)

const banner = useDeployEnv()
const localeOptions = buildLocaleOptions(availableLocales, t)

const drawer = ref(true)
const userMenuOpen = ref(false)

// Tightened admin session timeout (D-11): 30-min idle + 8-h absolute cap.
// Mounted with the authenticated shell so it covers every admin surface;
// the backend session lifetime is the authoritative server-side bound.
useIdleTimeout()

function badgeCount(key: NavBadgeKey | undefined): number {
  if (key === 'creatorApprovals') return creatorApprovals.value
  if (key === 'kycQueue') return kycQueue.value
  return 0
}

async function signOut(): Promise<void> {
  userMenuOpen.value = false
  await authStore.logout()
  await router.push({ name: 'auth.sign-in' })
}
</script>

<template>
  <v-app data-test="admin-layout">
    <!-- ─── Env banner (persistent) ────────────────────────────── -->
    <v-system-bar
      :color="banner.color"
      window
      class="justify-center text-uppercase font-weight-bold admin-env-banner"
      :data-test="`admin-env-banner-${banner.env}`"
      data-testid="admin-env-banner"
    >
      <span>{{ t(banner.labelKey) }}</span>
    </v-system-bar>

    <!-- ─── Navigation drawer (220px, never collapses) ─────────── -->
    <v-navigation-drawer
      v-model="drawer"
      permanent
      width="280"
      data-test="admin-sidebar"
      data-testid="admin-sidebar"
    >
      <div class="px-4 py-3 d-flex align-center ga-2" data-test="admin-wordmark">
        <v-icon icon="mdi-shield-crown-outline" color="primary" size="small" />
        <span class="text-subtitle-2 font-weight-bold text-truncate">
          {{ t('app.title') }}
        </span>
      </div>

      <v-divider />

      <v-list v-model:opened="openedGroups" nav density="compact" class="mt-1">
        <template v-for="entry in NAV_ENTRIES" :key="entry.key">
          <!-- Group with children -->
          <v-list-group v-if="isNavGroup(entry)" :value="entry.key">
            <template #activator="{ props: groupProps }">
              <v-list-item
                v-bind="groupProps"
                :prepend-icon="entry.icon"
                :title="t(`app.nav.${entry.key}`)"
                :data-testid="`nav-group-${entry.key}`"
              />
            </template>

            <v-list-item
              v-for="child in entry.children"
              :key="child.key"
              :prepend-icon="child.icon"
              :to="child.external ? undefined : { name: child.routeName }"
              :href="child.external ? child.href : undefined"
              :target="child.external ? '_blank' : undefined"
              rounded="lg"
              color="primary"
              :data-testid="`nav-${child.key}`"
            >
              <v-list-item-title class="d-flex align-center ga-2">
                <span>{{ t(`app.nav.${child.key}`) }}</span>
                <v-chip
                  v-if="child.comingSoon"
                  size="x-small"
                  variant="tonal"
                  color="grey"
                  :data-testid="`nav-soon-${child.key}`"
                >
                  {{ t('app.nav.comingSoon') }}
                </v-chip>
                <v-chip
                  v-else-if="badgeCount(child.badge) > 0"
                  size="x-small"
                  color="primary"
                  variant="flat"
                  :data-testid="`nav-badge-${child.key}`"
                >
                  {{ badgeCount(child.badge) }}
                </v-chip>
              </v-list-item-title>
            </v-list-item>
          </v-list-group>

          <!-- Top-level leaf -->
          <v-list-item
            v-else
            :prepend-icon="entry.icon"
            :to="{ name: (entry as NavLeaf).routeName }"
            :title="t(`app.nav.${entry.key}`)"
            rounded="lg"
            color="primary"
            :data-testid="`nav-${entry.key}`"
          />
        </template>
      </v-list>
    </v-navigation-drawer>

    <!-- ─── Top bar (48px) ─────────────────────────────────────── -->
    <v-app-bar :height="48" elevation="1" data-test="admin-topbar">
      <v-app-bar-nav-icon size="small" @click="drawer = !drawer" />
      <v-spacer />

      <v-menu
        v-model="userMenuOpen"
        offset-y
        :close-on-content-click="false"
        data-test="admin-user-menu-trigger-wrapper"
      >
        <template #activator="{ props: menuProps }">
          <v-btn
            v-bind="menuProps"
            variant="text"
            class="text-none"
            data-test="admin-user-menu-btn"
          >
            <v-avatar size="28" color="primary" class="mr-2">
              <span class="text-caption font-weight-bold text-white">
                {{ (user?.attributes.name ?? '?')[0]?.toUpperCase() }}
              </span>
            </v-avatar>
            <span class="text-body-2 d-none d-sm-inline" data-test="admin-user-menu-name">
              {{ user?.attributes.name ?? '' }}
            </span>
            <v-icon icon="mdi-chevron-down" size="small" class="ml-1" />
          </v-btn>
        </template>

        <v-card min-width="240" data-test="admin-user-menu">
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
              data-test="admin-user-menu-locale-switcher"
            />
          </v-card-text>

          <v-divider />

          <v-list density="compact">
            <v-list-item
              :disabled="isLoggingOut"
              :title="isLoggingOut ? t('app.userMenu.signingOut') : t('app.userMenu.signOut')"
              prepend-icon="mdi-logout"
              data-test="admin-sign-out-btn"
              @click="signOut"
            />
          </v-list>
        </v-card>
      </v-menu>
    </v-app-bar>

    <!-- ─── Main content (fluid, no max-width) ─────────────────── -->
    <v-main data-test="admin-main">
      <v-container fluid class="pa-6">
        <slot />
      </v-container>
    </v-main>
  </v-app>
</template>

<style scoped>
.admin-env-banner {
  letter-spacing: 0.08em;
}
</style>
