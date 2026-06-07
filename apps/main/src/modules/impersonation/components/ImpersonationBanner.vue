<script setup lang="ts">
/**
 * Persistent impersonation banner (Sprint 13, D-10).
 *
 * A high-contrast bar pinned to the top of the shell whenever the admin is
 * impersonating a user. Rendered as a Vuetify `<v-system-bar>` as the FIRST
 * child INSIDE each layout's `<v-app>` (mirrors the admin SPA's env banner).
 * Living inside the layout means the Vuetify layout engine reserves space
 * for it and pushes the app-bar / navigation-drawer / main content down —
 * so the banner can never mask the navbar (the bug this replaced the old
 * `position: fixed` root-level element to fix).
 *
 * It shows WHO the session is acting as is implicit (the SPA is rendering
 * as them); the bar's job is the standing reminder + the advisory
 * countdown + the one-click exit. The countdown is cosmetic: the
 * EnforceImpersonation middleware is the authoritative TTL and will reject
 * an expired session server-side regardless of what the timer shows.
 */

import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useImpersonationStore } from '../stores/useImpersonationStore'

const { t } = useI18n()
const store = useImpersonationStore()

const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  ticker = setInterval(() => {
    now.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  if (ticker !== null) clearInterval(ticker)
})

const remainingMs = computed<number>(() => {
  if (store.expiresAtMs === null) return 0
  return Math.max(0, store.expiresAtMs - now.value)
})

const countdown = computed<string>(() => {
  const totalSeconds = Math.floor(remainingMs.value / 1000)
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return `${minutes}:${String(seconds).padStart(2, '0')}`
})

async function onEnd(): Promise<void> {
  await store.end()
  // Returning to a clean anonymous session: a hard reload drops the
  // impersonated `web` session and re-renders the public app.
  window.location.assign('/sign-in')
}
</script>

<template>
  <v-system-bar
    v-if="store.active"
    color="error"
    window
    :height="40"
    class="impersonation-banner justify-center text-white"
    role="alert"
    data-testid="impersonation-banner"
  >
    <span class="impersonation-banner__label font-weight-bold">
      {{ t('impersonation.banner.message') }}
    </span>
    <span class="impersonation-banner__timer mx-4" data-testid="impersonation-banner-countdown">
      {{ t('impersonation.banner.expires_in', { time: countdown }) }}
    </span>
    <v-btn
      size="x-small"
      variant="flat"
      color="white"
      class="impersonation-banner__end text-error text-none"
      :loading="store.isEnding"
      :disabled="store.isEnding"
      data-testid="impersonation-banner-end"
      @click="onEnd"
    >
      {{ t('impersonation.banner.end') }}
    </v-btn>
  </v-system-bar>
</template>

<style scoped>
/* The banner now lives INSIDE the layout's <v-app>, so Vuetify theme
   tokens resolve here — `color="error"` paints the danger background and
   the layout engine reserves the bar's height (no manual offset needed). */
.impersonation-banner__timer {
  font-variant-numeric: tabular-nums;
  opacity: 0.9;
}
</style>
