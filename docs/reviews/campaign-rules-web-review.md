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
- Vitest main: 4136 tests, 2 failed. Both are `appIconAssets.spec` (splash.png). They also fail at `270d097e` without these changes, so they are environment-dependent and pre-existing.
- Vitest admin 690/690. api-client 228/228.
- Break-reverts, all caught: region switch flag gate, omit-restrict-when-OFF, 422 offer_locked race handling, status-hide (detail and drawer), money format (chat, notification), chat fallback, locked-branch order, 422 region handler, `is_region_locked` computed, locked-dialog unchanged hint.
- Native polarity: job detail (web and native), creator assignment list (native and web), creator assignment detail (web and native), `ChatPanel.native.spec`.

## Postgres result

Recorded in the plan section 14. CampaignAssignmentContractTest and the edit race test pass on Postgres 16. Four failures that exist on `origin/main` are unrelated. Two jsonb key-order asserts of ours were made order-agnostic.

## Walks

- Creator baseline: `COMPARE OK: 142 targets identical to baseline.json`. The walk has no API (503s), so the changed creator surfaces (job detail, assignment detail and list, chat, notifications, preferences) never render with data there. **Zero differences, and that does not prove the listed surfaces unchanged**, so their behaviour is covered by the specs and the screenshots below.
- Agency walk (`walk-l4.mjs`): 96 stops, 0 problems.

## Not done / deferred

- Full Playwright suite and the Playwright edits (plan section 7) are not run. They need the dev stack down and their own E2E DB.
- Native build not started.

## Screenshots (walk harness, mock API, flags ON)

campaign-rules-assets/: `1-form-switch-on.png`, `2-edit-offer-editable.png`, `2-edit-offer-locked.png`, `3-chat-history-line.png`, `4-creator-job-locked-dark.png`, `5-creator-assignment-after-edit.png`
