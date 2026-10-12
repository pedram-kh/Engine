/**
 * AH-102 (C4.1) — the device-side push bridge, behind an injectable interface.
 *
 * Everything the app does with the OS push stack goes through {@link PushClient},
 * so the registration logic (`pushRegistration.ts`) is specced with a fake and the
 * one Capacitor call site stays a few lines, same shape as `appResume.ts`'s
 * `capacitorResumeSource`. The plugin is `@capacitor-firebase/messaging`, loaded
 * with a dynamic `import()` so it is never evaluated on the web build.
 *
 * Privacy: nothing here asks the OS for permission or mints a token on its own.
 * Auto-init is OFF natively (Android manifest meta-data, C4.2), so no token
 * exists until {@link PushClient.getToken} is called — which only happens after the
 * creator accepts the soft-ask.
 */

/** The three states the app acts on. `prompt-with-rationale` collapses into `prompt`. */
export type DevicePermission = 'prompt' | 'granted' | 'denied'

export interface PushChannelSpec {
  /** Stable id; the server message sets the same `channel_id`. */
  id: string
  /** User-visible name in the OS notification settings (localised). */
  name: string
  description: string
}

/** Resolves to the unsubscribe. */
export type PushSubscription<T> = (listener: (event: T) => void) => Promise<() => void>

export interface PushClient {
  checkPermission(): Promise<DevicePermission>
  requestPermission(): Promise<DevicePermission>
  /** Mints (or returns) this install's FCM token. Only call after consent. */
  getToken(): Promise<string>
  /** Invalidates the token at FCM, so a leaked copy becomes `UNREGISTERED`. */
  deleteToken(): Promise<void>
  createChannel(channel: PushChannelSpec): Promise<void>
  /** FCM rotated the token. */
  onTokenReceived: PushSubscription<string>
  /** A push arrived while the app was in the foreground. */
  onNotificationReceived: PushSubscription<void>
  /**
   * The user tapped a delivered notification (app was backgrounded or killed).
   * The event is the notification's `data` object and NOTHING else (AH-103): it is
   * `unknown` on purpose — the OS adds vendor keys of its own (numbers, a nested
   * `aps`, a stringified Bundle; inventory §9.3), so the one parser
   * (`parsePushTapData`) is the only place that decides what in it is trusted.
   */
  onNotificationTapped: PushSubscription<unknown>
}

function toDevicePermission(state: string): DevicePermission {
  if (state === 'granted' || state === 'denied') {
    return state
  }
  return 'prompt'
}

/* c8 ignore start -- the Capacitor bridge; exercised on a device (the AH-102
   Android walk, C4.3) and injected as a fake everywhere else. */
export const capacitorPushClient: PushClient = {
  async checkPermission() {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
    return toDevicePermission((await FirebaseMessaging.checkPermissions()).receive)
  },
  async requestPermission() {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
    return toDevicePermission((await FirebaseMessaging.requestPermissions()).receive)
  },
  async getToken() {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
    return (await FirebaseMessaging.getToken()).token
  },
  async deleteToken() {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
    await FirebaseMessaging.deleteToken()
  },
  async createChannel(channel) {
    const { FirebaseMessaging, Importance } = await import('@capacitor-firebase/messaging')
    await FirebaseMessaging.createChannel({
      id: channel.id,
      name: channel.name,
      description: channel.description,
      importance: Importance.High,
    })
  },
  async onTokenReceived(listener) {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
    const handle = await FirebaseMessaging.addListener('tokenReceived', (event) =>
      listener(event.token),
    )
    return () => {
      void handle.remove()
    }
  },
  async onNotificationReceived(listener) {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
    const handle = await FirebaseMessaging.addListener('notificationReceived', () =>
      listener(undefined),
    )
    return () => {
      void handle.remove()
    }
  },
  async onNotificationTapped(listener) {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
    // `event.notification.data` only: on Android the tap event has no title/body, and
    // nothing else on it is needed (device spike, inventory §9.2).
    const handle = await FirebaseMessaging.addListener('notificationActionPerformed', (event) =>
      listener(event.notification?.data),
    )
    return () => {
      void handle.remove()
    }
  },
}
/* c8 ignore stop */
