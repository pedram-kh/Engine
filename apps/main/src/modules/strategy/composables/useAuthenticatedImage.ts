/**
 * PL-2.3 (G3) — an image the API serves behind its policy check. The SPA authenticates with the session cookie (plus the
 * CSRF header on writes), so a plain `<img src>` to the API would work only by accident of cookie rules and would give
 * no way to show a failure; instead the bytes are fetched through the app's own HTTP client and shown from a blob URL.
 *
 * Every blob URL this creates is revoked when the path changes and when the component that owns it unmounts (a card
 * removed, the page left), so none outlives its tile. `liveBlobUrlCount` exists for the leak check: it is the number of
 * URLs created and not yet revoked.
 */
import { onBeforeUnmount, ref, watch, type Ref } from 'vue'

import { strategyApi } from '../api/strategy.api'

let live = 0

/** URLs created by this module and not yet revoked; 0 when every owner has gone. */
export function liveBlobUrlCount(): number {
  return live
}

export function useAuthenticatedImage(path: () => string | null): {
  url: Ref<string | null>
  failed: Ref<boolean>
  loading: Ref<boolean>
} {
  const url = ref<string | null>(null)
  const failed = ref(false)
  const loading = ref(false)
  let current: string | null = null
  let ticket = 0

  function revoke(): void {
    if (current !== null) {
      URL.revokeObjectURL(current)
      live -= 1
      current = null
    }
    url.value = null
  }

  async function load(p: string | null): Promise<void> {
    const mine = ++ticket
    revoke()
    failed.value = false
    if (p === null) {
      loading.value = false
      return
    }
    loading.value = true
    try {
      const blob = await strategyApi.imageBlob(p)
      if (mine !== ticket) return
      current = URL.createObjectURL(blob)
      live += 1
      url.value = current
    } catch {
      if (mine === ticket) failed.value = true
    } finally {
      if (mine === ticket) loading.value = false
    }
  }

  watch(path, (p) => void load(p), { immediate: true })
  onBeforeUnmount(() => {
    ticket++
    revoke()
  })

  return { url, failed, loading }
}
