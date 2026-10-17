import Capacitor

/// The app's root view controller. It exists only to register the in-repo
/// `CatalystBadgePlugin`: Capacitor discovers npm plugins automatically but
/// not plugins that live in the app target, so they are registered here once
/// the bridge is up (`capacitorDidLoad`, Capacitor's documented hook for this).
///
/// `SceneDelegate` builds this in code, so there is no storyboard `customClass`
/// to change.
class MainViewController: CAPBridgeViewController {
    /// Capacitor invokes `capacitorDidLoad()` twice on the same controller
    /// instance (observed in the AH-108 spike), so registration is guarded.
    private var badgePluginRegistered = false

    override open func capacitorDidLoad() {
        guard !badgePluginRegistered else { return }
        badgePluginRegistered = true
        bridge?.registerPluginInstance(CatalystBadgePlugin())
    }
}
