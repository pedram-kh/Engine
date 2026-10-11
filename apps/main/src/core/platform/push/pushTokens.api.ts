import { http } from '@/core/api'

import type { NativePlatform } from '../isNativePlatform'

/**
 * AH-102 — `POST` / `DELETE /me/push-tokens` (creator-app bearer only). The owner
 * is never in the body: the server binds the token to the authenticated creator
 * and to the device login the request arrived under, so signing out of that
 * login cascades the row away.
 */
export interface PushTokensApi {
  register(token: string, platform: NativePlatform, appVersion?: string): Promise<void>
  forget(token: string): Promise<void>
}

export const pushTokensApi: PushTokensApi = {
  async register(token, platform, appVersion) {
    await http.post('/me/push-tokens', {
      platform,
      token,
      ...(appVersion !== undefined ? { app_version: appVersion } : {}),
    })
  },
  async forget(token) {
    await http.delete('/me/push-tokens', { token })
  },
}
