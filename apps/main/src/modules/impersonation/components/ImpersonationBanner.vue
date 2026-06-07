<script setup lang="ts">
/**
 * Persistent impersonation banner (Sprint 13, D-10).
 *
 * A fixed, high-contrast bar pinned to the top of the viewport whenever
 * the admin is impersonating a user. Rendered at the App.vue root —
 * OUTSIDE the per-layout `<v-app>` — so it is a plain styled element (not
 * a Vuetify component) and never violates the single-`<v-app>` invariant.
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
  <div
    v-if="store.active"
    class="impersonation-banner"
    role="alert"
    data-testid="impersonation-banner"
  >
    <span class="impersonation-banner__label">
      {{ t('impersonation.banner.message') }}
    </span>
    <span class="impersonation-banner__timer" data-testid="impersonation-banner-countdown">
      {{ t('impersonation.banner.expires_in', { time: countdown }) }}
    </span>
    <button
      type="button"
      class="impersonation-banner__end"
      :disabled="store.isEnding"
      data-testid="impersonation-banner-end"
      @click="onEnd"
    >
      {{ t('impersonation.banner.end') }}
    </button>
  </div>
</template>

<style scoped>
/* Pinned at the App.vue root, OUTSIDE any <v-app>, so the Vuetify
   --v-theme-* variables are out of scope here. Consume the global
   :root design tokens (@catalyst/design-tokens) instead, which is why
   this is the danger primitive rather than the Vuetify error theme color. */
.impersonation-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 3000;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 8px 16px;
  background: var(--danger-500);
  color: var(--brand-cream);
  font-size: 0.875rem;
  font-weight: 600;
  border-bottom: 2px solid var(--danger-100);
}

.impersonation-banner__timer {
  font-variant-numeric: tabular-nums;
  opacity: 0.9;
}

.impersonation-banner__end {
  background: var(--brand-cream);
  color: var(--danger-500);
  border: none;
  border-radius: var(--radius-sm, 4px);
  padding: 4px 12px;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.impersonation-banner__end:disabled {
  opacity: 0.6;
  cursor: default;
}
</style>
