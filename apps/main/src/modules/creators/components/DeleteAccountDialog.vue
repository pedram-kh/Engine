<script setup lang="ts">
/**
 * DeleteAccountDialog — the confirmation for "Delete my account" (AH-104b-del B4).
 *
 * A `v-dialog` on the web and a `v-bottom-sheet` in the Capacitor app (the same split the
 * job-apply dialog uses). What it asks for, in the order a person meets it:
 *
 *   1. a tick on "I understand what will be deleted and what will stay" (sent as
 *      `acknowledged: true`, the one thing the server asks for beyond the credentials);
 *   2. the current password, and the authenticator / recovery code when `two_factor_enabled`;
 *   3. the confirmation word, typed, in the interface language (P6).
 *
 * The word is a UX gate (see `deleteWord.ts`), not a control: the button simply stays
 * disabled until all of it is present. The server re-checks the password and the code and
 * answers a wrong one with a 403, never a 401, so a typo here cannot sign the creator out.
 *
 * On success the server has already ended every session, so the store clears the local
 * state without a logout call (`requestAccountDeletion`), and the creator lands on sign-in
 * with a banner naming the date. Nothing here calls the erasure engine: the account is
 * only scheduled.
 */

import { ApiError } from '@catalyst/api-client'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { VBottomSheet } from 'vuetify/components/VBottomSheet'
import { VDialog } from 'vuetify/components/VDialog'

import { SUPPORT_EMAIL } from '@/core/config/support'
import { isNativePlatform } from '@/core/platform/isNativePlatform'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'
import { useMfaCodeInput } from '@/modules/auth/composables/useMfaCodeInput'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import { typedWordMatches } from '../security/deleteWord'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ (e: 'update:modelValue', value: boolean): void }>()

const { t, te, locale } = useI18n()
const router = useRouter()
const store = useAuthStore()
const isNative = isNativePlatform()

const acknowledged = ref(false)
const password = ref('')
const code = ref('')
const typed = ref('')
const mfaInput = useMfaCodeInput()
const submitting = ref(false)

const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})
const blockers = ref<string[]>([])
/** Seconds left on a 429; the button stays disabled until it reaches 0. */
const waitSeconds = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

const needsCode = computed(() => store.user?.attributes.two_factor_enabled === true)
const word = computed(() => t('creator.ui.security.dialog.word'))

const wordMatches = computed(() => typedWordMatches(typed.value, word.value, locale.value))

/** The 429 message counts down: its `{seconds}` is the live remainder, not the first answer. */
const errorBag = computed(() =>
  errorKey.value === 'rate_limit.exceeded' ? { seconds: waitSeconds.value } : errorValues.value,
)

const canSubmit = computed(
  () =>
    acknowledged.value &&
    password.value !== '' &&
    (!needsCode.value || code.value.trim() !== '') &&
    wordMatches.value &&
    waitSeconds.value === 0 &&
    !submitting.value,
)

function stopTimer(): void {
  if (timer !== null) {
    clearInterval(timer)
    timer = null
  }
}

function startCountdown(seconds: number): void {
  stopTimer()
  waitSeconds.value = Math.max(0, Math.ceil(seconds))
  if (waitSeconds.value === 0) {
    return
  }
  timer = setInterval(() => {
    waitSeconds.value = Math.max(0, waitSeconds.value - 1)
    if (waitSeconds.value === 0) {
      stopTimer()
      // "Try again in 0 seconds" is noise: the wait is over, so the message goes with it.
      if (errorKey.value === 'rate_limit.exceeded') {
        errorKey.value = null
      }
    }
  }, 1000)
}

function reset(): void {
  acknowledged.value = false
  password.value = ''
  code.value = ''
  typed.value = ''
  mfaInput.reset()
  errorKey.value = null
  errorValues.value = {}
  blockers.value = []
  submitting.value = false
  waitSeconds.value = 0
  stopTimer()
}

// A reopened dialog starts clean: a password must never outlive the dialog it was typed in.
watch(
  () => props.modelValue,
  (open) => {
    if (!open) {
      reset()
    }
  },
)

onBeforeUnmount(stopTimer)

function close(): void {
  emit('update:modelValue', false)
}

function blockerKey(reason: string): string {
  const key = `account_deletion.blockers.${reason}`
  return te(key) ? key : 'account_deletion.blockers.other'
}

function showError(err: unknown): void {
  errorKey.value = null
  errorValues.value = {}
  blockers.value = []

  if (!(err instanceof ApiError)) {
    errorKey.value = 'auth.ui.errors.unknown'
    return
  }

  const meta = err.details[0]?.meta

  if (err.status === 429) {
    const seconds = typeof meta?.['seconds'] === 'number' ? meta['seconds'] : 60
    startCountdown(seconds)
    errorKey.value = 'rate_limit.exceeded'
    errorValues.value = { seconds }
    return
  }

  if (err.code === 'account_deletion.blocked') {
    const reasons = Array.isArray(meta?.['reasons']) ? (meta['reasons'] as unknown[]) : []
    blockers.value = reasons.filter((r): r is string => typeof r === 'string')
    errorKey.value = 'account_deletion.blocked'
    return
  }

  // The flag was switched off while the page was open: the API answers 404 as if the route
  // did not exist. Say what is true for the person, not "not found".
  if (err.status === 404) {
    errorKey.value = 'account_deletion.not_available'
    return
  }

  const resolved = resolveErrorMessage(err, (key) => te(key))
  errorKey.value = resolved.key
  errorValues.value = resolved.values
}

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }
  submitting.value = true
  errorKey.value = null
  blockers.value = []
  try {
    const result = await store.requestAccountDeletion({
      password: password.value,
      ...(needsCode.value ? { mfa_code: code.value.trim() } : {}),
      acknowledged: true,
    })
    close()
    await router.replace({
      name: 'auth.sign-in',
      query: { reason: 'deletion_scheduled', date: result.scheduledFor },
    })
  } catch (err) {
    showError(err)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <component
    :is="isNative ? VBottomSheet : VDialog"
    :model-value="modelValue"
    max-width="520"
    persistent
    data-test="delete-account-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="delete-account-title" data-test="delete-account-dialog-title">
        {{ t('creator.ui.security.dialog.title') }}
      </v-card-title>

      <v-card-text>
        <form novalidate class="delete-account-form" @submit.prevent="submit">
          <v-checkbox
            v-model="acknowledged"
            :label="t('creator.ui.security.dialog.acknowledge')"
            density="compact"
            hide-details
            class="delete-account-ack"
            data-test="delete-account-ack"
          />

          <v-text-field
            v-model="password"
            :label="t('auth.ui.labels.current_password')"
            type="password"
            autocomplete="current-password"
            variant="outlined"
            class="mt-3"
            data-test="delete-account-password"
          />

          <v-text-field
            v-if="needsCode"
            v-model="code"
            :label="t(mfaInput.labelKey.value)"
            :placeholder="mfaInput.placeholder.value"
            type="text"
            :inputmode="mfaInput.inputmode.value"
            maxlength="32"
            :autocomplete="mfaInput.autocomplete.value"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            variant="outlined"
            data-test="delete-account-code"
          />
          <v-btn
            v-if="needsCode"
            variant="text"
            size="small"
            class="mb-2 px-0"
            :disabled="submitting"
            data-test="delete-account-mfa-toggle"
            @click="mfaInput.toggle"
          >
            {{ t(mfaInput.toggleKey.value) }}
          </v-btn>

          <p class="text-body-2 mb-2" data-test="delete-account-type-prompt">
            {{ t('creator.ui.security.dialog.typePrompt', { word }) }}
          </p>
          <v-text-field
            v-model="typed"
            :label="t('creator.ui.security.dialog.typeLabel')"
            type="text"
            autocomplete="off"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            variant="outlined"
            data-test="delete-account-word"
          />

          <div role="alert" aria-live="polite" data-test="delete-account-error">
            <p v-if="errorKey !== null" class="text-error text-body-2 mb-2">
              {{ t(errorKey, errorBag) }}
            </p>
            <ul
              v-if="blockers.length > 0"
              class="text-body-2 mb-2"
              data-test="delete-account-blockers"
            >
              <li v-for="reason in blockers" :key="reason">{{ t(blockerKey(reason)) }}</li>
            </ul>
            <p v-if="blockers.length > 0" class="text-body-2" data-test="delete-account-contact">
              {{ t('account_deletion.blockers.contact', { email: SUPPORT_EMAIL }) }}
            </p>
          </div>
        </form>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="submitting"
          data-test="delete-account-cancel"
          @click="close"
        >
          {{ t('auth.ui.actions.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          variant="flat"
          :loading="submitting"
          :disabled="!canSubmit"
          data-test="delete-account-submit"
          @click="submit"
        >
          {{ t('creator.ui.security.delete.button') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </component>
</template>

<style scoped>
/* One left edge for the whole sheet (the native walk, 2026-10-22). Vuetify pads the title by 16px
 * and the body by 24px, and a compact checkbox draws its box 5px inside its own left edge (2px of
 * wrapper slack, 3px of glyph), so the title, the box, the password field and the typed-word label
 * each started somewhere else. The title takes the body's 24px; the box is pulled back by exactly
 * what it was inset by. */
.delete-account-title {
  padding-inline: 24px;
  /* A longer translation wraps instead of ending in an ellipsis. */
  white-space: normal;
}

.delete-account-ack :deep(.v-selection-control) {
  margin-inline-start: calc((24px - var(--v-selection-control-size)) / 2 - 3px);
}
</style>
