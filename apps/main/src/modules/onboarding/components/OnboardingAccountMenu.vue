<script setup lang="ts">
/**
 * OnboardingAccountMenu — the way to Security from inside the wizard (AH-104d, P6).
 *
 * A creator who signed up in the app lands in the wizard and, until now, had no
 * path to account deletion until they reached the dashboard. Apple 5.1.1(v)
 * wants deletion reachable from within the app wherever an account can be made.
 *
 * It is an avatar button that opens a small menu: the account's email and one
 * entry, Security. It holds nothing else on purpose: locale, theme and sign-out
 * already live elsewhere in this chrome ("Save and exit" signs out), and a menu
 * that held one thing besides Security would repeat the dead-chrome mistake
 * AH-101 U5 removed.
 *
 * It reads `features.account_deletion` and nothing else. While that is off, it
 * renders NOTHING — not an empty button — because Security itself is unreachable
 * (the route is guarded on the same flag).
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const { t } = useI18n()
const auth = useAuthStore()

const open = ref(false)

const visible = computed(() => auth.user?.attributes.features?.account_deletion === true)
const initial = computed(() => (auth.user?.attributes.name ?? '?')[0]?.toUpperCase())
</script>

<template>
  <v-menu v-if="visible" v-model="open" offset-y :close-on-content-click="false">
    <template #activator="{ props: menuProps }">
      <v-btn
        v-bind="menuProps"
        icon
        variant="text"
        :aria-label="t('app.nav.menu')"
        data-test="onboarding-account-menu-btn"
      >
        <v-avatar size="32" color="primary">
          <span class="text-caption font-weight-bold text-white">{{ initial }}</span>
        </v-avatar>
      </v-btn>
    </template>

    <v-card min-width="240" data-test="onboarding-account-menu">
      <v-card-text class="pb-2">
        <p class="text-caption text-medium-emphasis mb-0" data-test="onboarding-account-email">
          {{ auth.user?.attributes.email ?? '' }}
        </p>
      </v-card-text>

      <v-divider />

      <v-list density="compact">
        <v-list-item
          :to="{ name: 'creator.security' }"
          :title="t('creator.ui.security.title')"
          prepend-icon="mdi-shield-lock-outline"
          data-test="onboarding-account-security"
          @click="open = false"
        />
      </v-list>
    </v-card>
  </v-menu>
</template>
