# Screenshot shot list (AH-105)

**English only for 1.0, plain captures, no framing, no marketing text on the image** (Q3, ruled 2026-10-29). The Spanish listing text uses these same screenshots. **For Pedram:** you run the capture and approve the set. The data comes from the local seeder (`apps/api/database/seeders/StoreScreenshotSeeder.php`), which is invented from end to end: no real person, brand, handle or face (the images are generated gradients).

## What you need

| Item                   | Value                                                                                                                                                                                                                                                       |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Size (from memory)     | **iPhone 6.9": 1320 × 2868**, PNG or JPEG, no alpha. ASC also takes 1290 × 2796 or 1260 × 2736 (read the upload screen). **No iPad set** (D1: iPhone-only). One to ten shots; we use six.                                                                   |
| Simulator              | **iPhone 17 Pro Max**, iOS 26.0 (1320 × 2868 natively; present on this machine as of 2026-10-29)                                                                                                                                                            |
| Build                  | `pnpm --filter @catalyst/main build:native:dev` (`apps/main/package.json:10`), which reads `.env.native-development` and the API at `http://localhost:8000/api/v1` (`NSAllowsLocalNetworking` is set in `Info.plist:79-81`). Needs the local stack running. |
| **Build after AH-111** | The banner reword (AH-111) must be in the build, or the dashboard says "coming soon". It is in the S2 commit.                                                                                                                                               |
| Sign in as             | `StoreScreenshotSeeder::CREATOR_EMAIL` with `StoreScreenshotSeeder::PASSWORD` (a local-only constant in the seeder; **never reuse it anywhere else, and it is not the production demo password**)                                                           |

## Prepare

```sh
# 1. Seed (local or testing only; it throws elsewhere)
cd apps/api && php artisan db:seed --class='Database\Seeders\StoreScreenshotSeeder'

# 2. Build the app against the local API, sync, run on the simulator
cd apps/main && pnpm build:native:dev && pnpm cap sync ios && pnpm cap run ios --target D8D01D55-BA36-4677-9052-3ECA5C2FD303

# 3. Make the status bar clean (9:41, full battery, full signal)
xcrun simctl status_bar booted override --time "9:41" --batteryState charged --batteryLevel 100 --cellularMode active --cellularBars 4 --wifiBars 3 --operatorName ""

# 4. Capture, after navigating to each screen (one command per shot)
xcrun simctl io booted screenshot --type=png ~/Desktop/catalyst-shots/01-dashboard.png

# 5. Done: clear the override
xcrun simctl status_bar booted clear
```

Check the file after each capture: `sips -g pixelWidth -g pixelHeight <file>` must say 1320 × 2868. If the capture has an alpha channel, re-export without it (for example, open in Preview and export as PNG without alpha, or `sips -s format jpeg`); ASC rejects alpha (from memory).

## The six shots, in this order

| #   | Screen                              | What must be on it (from the seeder)                                                                           | Do not show                                                                          |
| --- | ----------------------------------- | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| 01  | **Dashboard**                       | The welcome summary, profile strength, the invitations waiting. The AH-111 banner text, **not** "coming soon". | Any "soon", "coming" or "beta" text; a push soft-ask; a debug banner                 |
| 02  | **Campaign invitations (the list)** | Three invitations in three states (invited, countered, contracted), each with its status chip (AH-107)         | A status you cannot explain; an empty list                                           |
| 03  | **Jobs board**                      | Four listed jobs from the demo agency                                                                          | Real brand names (the seeder's four are invented; check they still read as such)     |
| 04  | **A conversation**                  | The relationship thread (about six messages), with **Report a problem** visible if it fits the frame           | A real email address; anything that looks like a real person's chat                  |
| 05  | **Profile and portfolio**           | The avatar, bio, two social accounts and four portfolio tiles                                                  | Real handles (the seeded two are invented); the date of birth and the address fields |
| 06  | **Notifications**                   | Eight notifications, a mix of read and unread                                                                  | A notification that names a real person or brand                                     |

## Do not show, on any shot

- A real person's face, name, handle or brand. If a generated image looks like a real logo, drop it.
- The Security page and the delete-account dialog (the review notes cover those), the sign-up wizard, an error toast, a debug or environment banner, the simulator's home indicator overlay or its chrome (use `simctl io`, not a window grab).
- Any text that says "soon", "coming", "beta", "test", "demo" or "TestFlight".
- The seeded email or password on screen.

## Strength score: check it, do not trust it

The dashboard's profile strength depends on feature flags and the hidden wizard steps. **The seeder's own spec measured 74 in the test environment** (not the "about 85" planned); the spec asserts only that the score equals the calculator's value, is below 100 and is at least 50 (`StoreScreenshotSeederTest.php`). **Read the number on your local dashboard before capturing.** A strength well below or above 100 reads best in the 70s to 90s. If it is not, tell me and I adjust the seeder; do not edit the profile by hand.

## After capturing (yours)

1. Look at all six at full size. Anything wrong, send me the number and the problem.
2. Approve the set. Upload to the 6.9" slot in ASC; ASC scales it for the other iPhone sizes if you leave them empty (from memory: read the screen).
3. Keep the originals outside the repo (they are binary and not needed in git).

## Post-launch list (not part of 1.0)

- **Spanish screenshots**: the same six screens, captured with the Spanish locale (the simulator language set to Spanish, or the in-app language), for the Spanish listing's own screenshot set. Ruled Q3: after launch.
- **iPad screenshots**: only if the app ever stops being iPhone-only (D1).
- **Framed or captioned screenshots**: only if you want marketing treatment later.
- **An app preview video**: not planned.

## Not verified

- Apple's current screenshot sizes and the rule about scaling a single size (from memory).
- The exact `pnpm cap` invocation on your machine (I did not run the simulator in this pass; the commands are the standard Capacitor ones).
- That the seeded data renders as intended on a device-size screen: **the seeder was verified by its spec, not by looking at the screens**.
