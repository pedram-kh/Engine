import { computed, ref } from 'vue'

/**
 * The "use a recovery code instead" switch for a field that takes a second-factor code
 * (AH-104b-del W10; the same server field `mfa_code` takes either kind).
 *
 * Why it exists: the server has always accepted a recovery code wherever it accepts an
 * authenticator code, but the field only ever said "Two-factor code" and asked the phone for
 * the numeric keypad, so on iOS a recovery code (`xxxx-xxxx-xxxx-xxxx`: letters and dashes)
 * could not be typed at all. The switch relabels the field and asks for the ordinary
 * keyboard, with every autocorrection off. It changes nothing that is sent.
 */
export function useMfaCodeInput() {
  const useRecovery = ref(false)

  const labelKey = computed(() =>
    useRecovery.value ? 'auth.ui.labels.recovery_code' : 'auth.ui.labels.totp_code',
  )
  const toggleKey = computed(() =>
    useRecovery.value
      ? 'auth.ui.actions.use_authenticator_code'
      : 'auth.ui.actions.use_recovery_code',
  )
  const inputmode = computed(() => (useRecovery.value ? 'text' : 'numeric'))
  const autocomplete = computed(() => (useRecovery.value ? 'off' : 'one-time-code'))
  const placeholder = computed(() => (useRecovery.value ? 'xxxx-xxxx-xxxx-xxxx' : undefined))

  function toggle(): void {
    useRecovery.value = !useRecovery.value
  }

  function reset(): void {
    useRecovery.value = false
  }

  return { useRecovery, labelKey, toggleKey, inputmode, autocomplete, placeholder, toggle, reset }
}
