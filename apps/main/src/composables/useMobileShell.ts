import { computed, type ComputedRef } from 'vue'
import { useDisplay } from 'vuetify'

import { isNativePlatform } from '@/core/platform/isNativePlatform'

export interface MobileShell {
  /** Running inside the Capacitor creator app. */
  isNative: boolean
  /**
   * Render the mobile chrome (bottom tabs, collapsed app bar). Inside the
   * app that is always — the shell is native, not merely narrow, so a
   * tablet gets it too. On the web it is the `smAndDown` breakpoint, as
   * before AH-100.
   */
  isMobile: ComputedRef<boolean>
}

export function useMobileShell(): MobileShell {
  const display = useDisplay()
  const isNative = isNativePlatform()
  return {
    isNative,
    isMobile: computed(() => isNative || display.smAndDown.value),
  }
}
