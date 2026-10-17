import Capacitor
import UIKit
import UserNotifications

/// AH-108 (4b): the home-screen icon badge. A deliberately tiny in-repo Capacitor
/// plugin (Pedram's Q7 ruling: no @capawesome dependency). iOS only; there is no
/// Android counterpart, and the JS wrapper never calls this off iOS.
///
/// `set({ count })` writes the number on the app icon; 0 clears it. Negative or
/// absent counts are treated as 0 so a bad caller can never leave a stale badge.
///
/// The badge only appears if the user granted notification authorisation with the
/// `.badge` option. The push permission request already includes it. A user who
/// declined notifications gets no badge and the call resolves `{ applied: false }`
/// rather than failing, because "the OS said no" is not an app error.
@objc(CatalystBadgePlugin)
public class CatalystBadgePlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "CatalystBadgePlugin"
    public let jsName = "CatalystBadge"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "set", returnType: CAPPluginReturnPromise)
    ]

    @objc func set(_ call: CAPPluginCall) {
        let requested = call.getInt("count") ?? 0
        let count = max(0, requested)

        if #available(iOS 16.0, *) {
            UNUserNotificationCenter.current().setBadgeCount(count) { error in
                call.resolve(["applied": error == nil, "count": count])
            }
        } else {
            // iOS 15 (the deployment target): the property is the only API.
            DispatchQueue.main.async {
                UIApplication.shared.applicationIconBadgeNumber = count
                call.resolve(["applied": true, "count": count])
            }
        }
    }
}
