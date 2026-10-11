<script setup lang="ts">
/**
 * PushStateRow — the one-line push state on the preferences page (AH-102, C4.1).
 *
 * A push column of switches is a control that FEELS dead when the OS has push
 * off for the app, so the page says so — and offers the way back:
 *   - not asked yet / asked once and declined → "Push is off on this device" +
 *     "Turn on", which is the soft-ask's second entry point;
 *   - blocked at the OS → explains that it is blocked in device settings. Opening
 *     the OS settings screen needs a native plugin the app does not ship (an open
 *     question in the AH-102 review), so there is deliberately NO button — no dead
 *     control. `notifications.preferences.push.openSettings` is translated and
 *     reserved for when that is decided.
 *   - granted → nothing.
 *
 * Native, push-capable builds only; renders nothing elsewhere. The permission is
 * re-read when the app returns to the foreground, because the creator may have
 * flipped it in the system settings while we were away.
 */

import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import { pushChannelAvailable } from '@/core/platform/push/pushChannelAvailable'
import { usePushRegistration } from '@/core/platform/push/usePushRegistration'

const { t } = useI18n()

const available = pushChannelAvailable()
const registration = available ? usePushRegistration() : null

const state = computed(() => registration?.permission.value ?? 'unsupported')

function refresh(): void {
  if (document.visibilityState === 'visible') {
    void registration?.refreshPermission()
  }
}

onMounted(() => {
  void registration?.refreshPermission()
  document.addEventListener('visibilitychange', refresh)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', refresh)
})

async function onEnable(): Promise<void> {
  await registration?.accept()
}
</script>

<template>
  <v-alert
    v-if="state === 'prompt' || state === 'denied'"
    type="info"
    variant="tonal"
    density="compact"
    class="mb-4"
    data-test="push-state-row"
    :data-state="state"
  >
    <div class="d-flex align-center justify-space-between ga-3">
      <span class="text-body-2" data-test="push-state-text">
        {{
          state === 'prompt'
            ? t('notifications.preferences.push.off')
            : t('notifications.preferences.push.blocked')
        }}
      </span>
      <v-btn
        v-if="state === 'prompt'"
        size="small"
        variant="flat"
        color="primary"
        data-test="push-state-enable"
        @click="onEnable"
      >
        {{ t('notifications.preferences.push.enable') }}
      </v-btn>
    </div>
  </v-alert>
</template>
