# AH-118 web checkpoint (B1-B3, admin entry, C1-C6)

Not pushed. Native build not started.

## Commits (on top of `270d097e`)

- `d8c2a666` api-client types and 24-locale strings
- `6cdddbe4` B1-B3 region switch, edit-offer dialog and drawer
- `f44329fe` admin push-type entry
- `f9443d8f` C1-C6 creator region lock, chat line, notification, frozen hashes
- next commit: dialog hint fix, screenshot tooling, this review

## Gates (apps/main unless noted)

- vue-tsc clean (main, admin); api-client tsc clean.
- ESLint 0 errors (2 existing warnings); Prettier clean.
- Vitest main: 4136 tests, 2 failed. Both are `appIconAssets.spec` (splash.png). They also fail on a clean checkout of `270d097e` (a worktree at that commit, node_modules linked, not `origin/main`), so they are environment-dependent and pre-existing (tech-debt "pixel-exact icon spec").
- Vitest admin 690/690. api-client 228/228.
- Break-reverts, all caught: region switch flag gate, omit-restrict-when-OFF, 422 offer_locked race handling, status-hide (detail and drawer), money format (chat, notification), chat fallback, locked-branch order, 422 region handler, `is_region_locked` computed, locked-dialog unchanged hint.
- Native polarity: job detail (web and native), creator assignment list (native and web), creator assignment detail (web and native), `ChatPanel.native.spec`.

## Postgres result

Recorded in the plan section 14. CampaignAssignmentContractTest and the edit race test pass on Postgres 16. Four failures that exist on `origin/main` are unrelated. Two jsonb key-order asserts of ours were made order-agnostic.

## Walks

- Creator baseline: `COMPARE OK: 142 targets identical to baseline.json`. The walk has no API (503s), so the changed creator surfaces (job detail, assignment detail and list, chat, notifications, preferences) never render with data there. **Zero differences, and that does not prove the listed surfaces unchanged**, so their behaviour is covered by the specs and the screenshots below.
- Agency walk (`walk-l4.mjs`): 96 stops, 0 problems.
- **Creator baseline re-recorded (2026-11-05, W1):** the Intel `baseline.json` did not reproduce on this Apple-silicon machine even on a clean `origin/main` (68 of 142 `styleHash` differences, identical on the clean tree and the working tree). It was re-recorded from a clean checkout of `3de0b8e3` (old file kept as `baseline-intel.json`; machine and browser noted in its README). The W1 tree compares 142/142 against the new file.

## Pedram's web walk (local stack, real API, both flags ON): PASSED, steps 1 to 8

Walked by Pedram on 2026-11-05 and 2026-11-06 against the local dev stack (API 8000, main 5173, admin 5174) with the seeded `walk118` data: an agency admin; one listed campaign with regions ES and PT and the restriction on; creators `walk-in` (ES), `walk-out` (DE) and `walk-none` (no country); `walk-in` invited with an editable offer. Steps 1 to 8 passed, **including the W1 fix** (the Overview "Settings" card switch and the "Regions" field with the Restricted pill, commit `ccdb7db8`).

- **W1 (found in the walk, fixed, accepted):** the campaign Overview did not show the restriction. Fixed agency-only: a fourth switch row that PATCHes `restrict_to_listing_regions` (hidden while the region flag is off; disabled unless listed with regions; an already-ON switch can always be turned off) and a "Regions" field with a "Restricted" pill. Six specs, break-revert measured (6 fail without the fix).
- **Not walked: the phone walk N1 to N10.** It needs native build 4 and stays **pending**.
- **Seed removed:** the `walk118` cleanup was run on 2026-11-06 and the local tables are back to the recorded "before" counts (users 3, agencies 2, agency_users 2, audit_logs 3, features 4; everything the seed added is zero). Two notes: (1) the script and the full before-snapshot in `/tmp` were cleared by a restart, so the cleanup was re-run from a re-created script and checked against the counts recorded when the seed ran, for the tables the walk touched; (2) the walk also made Pennant cache 10 default flag rows in `features`, which were removed with the two AH-118 rows (the app re-creates them on demand). Four `sessions` rows remain; they belong to no `walk118` user and their "before" count was not recorded.

## Not done / deferred

- Full Playwright suite and the Playwright edits (plan section 7) are not run. They need the dev stack down and their own E2E DB.
- Native build not started.

## Screenshots (walk harness, mock API, flags ON)

campaign-rules-assets/: `1-form-switch-on.png`, `2-edit-offer-editable.png`, `2-edit-offer-locked.png`, `3-chat-history-line.png`, `4-creator-job-locked-dark.png`, `5-creator-assignment-after-edit.png`
