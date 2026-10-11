<script setup lang="ts">
/**
 * PushSoftAskSheet — our own consent sheet, shown BEFORE the OS permission
 * prompt (AH-102, C4.1). The OS prompt is a one-shot: a "Don't allow" there is
 * hard to undo, so we ask in context first and only raise the system dialog once
 * the creator has said yes.
 *
 * Shown on the creator dashboard (the first screen after sign-in — never at cold
 * launch or on the sign-in screen), to creators who are pending review OR
 * approved and have never been asked. A decline starts a 30-day cool-down; an
 * OS-level deny never re-prompts (the preferences row explains instead).
 *
 * Native, push-capable builds only: on the web — and on iOS until AH-102-ios —
 * it renders nothing and calls nothing. `persistent`, so a stray tap outside
 * cannot dismiss it without an answer (an unanswered sheet would come back on
 * every visit).
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { pushChannelAvailable } from '@/core/platform/push/pushChannelAvailable'
import { usePushRegistration } from '@/core/platform/push/usePushRegistration'

const props = defineProps<{
  /** The creator is in a state that should be asked (pending or approved). */
  eligible: boolean
}>()

const { t } = useI18n()

const open = ref(false)
const busy = ref(false)
let evaluated = false

async function evaluate(): Promise<void> {
  if (evaluated || !props.eligible || !pushChannelAvailable()) {
    return
  }
  evaluated = true
  try {
    open.value = await usePushRegistration().isSoftAskDue()
  } catch {
    open.value = false
  }
}

async function onAccept(): Promise<void> {
  busy.value = true
  try {
    await usePushRegistration().accept()
  } catch {
    // The registration does not throw by design; if the bridge ever does, the
    // sheet must still close — the settings row remains the way back in.
  } finally {
    busy.value = false
    open.value = false
  }
}

function onDecline(): void {
  usePushRegistration().decline()
  open.value = false
}

onMounted(evaluate)
watch(() => props.eligible, evaluate)
</script>

<template>
  <v-bottom-sheet v-model="open" persistent data-test="push-soft-ask">
    <v-card class="push-soft-ask pa-6" data-test="push-soft-ask-card">
      <div class="push-soft-ask__icon mb-3">
        <v-icon icon="mdi-bell-ring-outline" size="32" color="primary" aria-hidden="true" />
      </div>
      <h2 class="text-h6 mb-2" data-test="push-soft-ask-title">
        {{ t('notifications.push.softAsk.title') }}
      </h2>
      <p class="text-body-1 mb-2" data-test="push-soft-ask-body">
        {{ t('notifications.push.softAsk.body') }}
      </p>
      <p class="text-caption text-medium-emphasis mb-6" data-test="push-soft-ask-note">
        {{ t('notifications.push.softAsk.note') }}
      </p>
      <div class="d-flex flex-column ga-2">
        <v-btn
          color="primary"
          size="large"
          block
          :loading="busy"
          :disabled="busy"
          data-test="push-soft-ask-accept"
          @click="onAccept"
        >
          {{ t('notifications.push.softAsk.accept') }}
        </v-btn>
        <v-btn
          variant="text"
          size="large"
          block
          :disabled="busy"
          data-test="push-soft-ask-decline"
          @click="onDecline"
        >
          {{ t('notifications.push.softAsk.decline') }}
        </v-btn>
      </div>
    </v-card>
  </v-bottom-sheet>
</template>

<style scoped>
.push-soft-ask {
  border-start-start-radius: 16px;
  border-start-end-radius: 16px;
  padding-block-end: calc(24px + env(safe-area-inset-bottom, 0px));
}

.push-soft-ask__icon {
  display: flex;
}
</style>
