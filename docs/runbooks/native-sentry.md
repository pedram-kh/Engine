# Native Sentry (iOS and Android): runbook

AH-121 C3. The phone app is `apps/main` inside Capacitor; the plugin is `@sentry/capacitor` 4.5.0 (pinned exactly).

## After every `pnpm install` or dependency change

```
cd apps/main
pnpm build:native          # or build:native:dev
pnpm sync:native           # cap sync, then the .map check over both native folders, then the plugin check
git diff ios/App/CapApp-SPM android ios/App/App.xcodeproj/project.xcworkspace
```

Read the diff. Expect only the Sentry plugin path to change when a peer moves.

## Why the plugin check exists

`ios/App/CapApp-SPM/Package.swift` names the plugin by a path with a pnpm peer-set hash
(`@sentry+capacitor@4.5.0_..._<hash>`). The hash changes when capacitor core, `@sentry/vue`,
pinia or typescript change. If the file is stale or hand-edited, the app still builds, without crash
reporting and without any error. `scripts/check-native-plugins.mjs` fails when:

- the pin in `package.json` is not exactly `4.5.0`;
- `Package.swift` has no `SentryCapacitor` entry, does not link the product, or the path does not exist;
- `android/capacitor.settings.gradle` has no `:sentry-capacitor` project or its folder is missing;
- `android/app/capacitor.build.gradle` does not depend on it.

## Recovery

1. `pnpm install` (so the folder the path names exists).
2. `pnpm build:native` (the web bundle must exist for `cap sync`).
3. `pnpm sync:native`. It rewrites `Package.swift` and the Gradle settings.
4. If a path collision appears, add a `symlink` entry for the plugin under `experimental.ios.spm.packageOptions` in `capacitor.config.ts` (as for `@capacitor-firebase/messaging`).
5. Commit the regenerated native files together with the dependency change.

## Test builds

`VITE_SENTRY_TEST_EVENT` (`1`, `crash`, `early`) makes a build fire one deliberate event per install. Never set it in a
file, never submit such a build to App Store review. The absence test fails if a normal build contains the path.
The DSN comes from `.env.native-development.local` (development) or the native-production env (release); never commit it.
After setting a DSN, check the native log and the browser console for "Invalid Sentry Dsn" before triggering anything.

## The Catalyst patch (`patches/@sentry__capacitor@4.5.0.patch`)

The plugin cannot pass these native options through (its JS `FilterNativeOptions` allow-list drops
`enableAutoSessionTracking`; its Swift `createOptions` reads none of the others), so sentry-cocoa's defaults
stayed on. The patch sets them off in `createOptions`, last: auto session tracking, network breadcrumbs,
network tracking, failed-request capture, auto breadcrumbs, metrics, and replay sample rates 0. Registered in the root
`package.json` (`pnpm.patchedDependencies`). `check-native-plugins.mjs` fails if the Swift source the app builds from
lacks any line. Changing the plugin version means: `pnpm patch @sentry/capacitor@<new>`, re-apply the same lines, `pnpm patch-commit`,
update `PINNED_VERSION` in the check and the pin test. `pnpm install` fails loudly if the old patch no longer applies.

## Device walk: after ANY plugin or SDK upgrade

Build a test build (`VITE_SENTRY_TEST_EVENT=crash`), sign in on a device or simulator, let it crash, relaunch to upload, export
the raw JSON of that one native crash and compare with the baseline below. A difference is a finding, not noise: stop and read why.

Baseline (C3, plugin 4.5.0 + sentry-cocoa 9.28.0 + the patch):

- Event `5878a46c5b9d444aa24e34df9d48cf0b` (signed-in native crash, build 6, 2026-11-14), read from the raw JSON.
- `sdk.integrations`: `SessionReplay`, `Crash` (replay listed but inert: rates 0, `onErrorSampleRate == 0` skips the start; `_dsc.replay_id` null). `features`: `experimentalViewRenderer`, `dataSwizzling`.
- Breadcrumbs: 7, all origin `capacitor` (5 `xhr`, 2 `navigation`), no query strings. None of `ui.lifecycle`, `device.event`, `http`; no `firebase` or `fcmtoken` string anywhere.
- `user`: `{id: <ulid>}` only; `user_type` tag; no email, no name, no IP; `release` `<bundle id>@<version>+<build>`, `dist` = build.
- Signed out: `user.id` `signed-out`, never a UUID.

## Not covered here

dSYM and native source-map upload (C5), Android (never built on the machine that did C3; tech-debt row 33).
