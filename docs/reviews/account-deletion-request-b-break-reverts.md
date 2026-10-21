# AH-104b-del Part B — break-revert logs (verbatim)

Harness output, unedited apart from terminal colour codes, for each Part B step. Each mutation is applied to the working tree, the named specs are run (Pest for the API, Vitest for the web), the
harness reads the run's **exit code** (non-zero means the mutation was CAUGHT) and restores the file byte-for-byte. The last line of each block, `restored-clean: True`, is `git status --porcelain` being
empty after the block. Where a mutation SURVIVED on the first run, the next block is the same mutation run again after the spec that closes it was added. Lines starting `spec:` are failing spec names, `msg :`
the first lines of the failure.

The harness is `run.py` (one script, `kind: api | web`) and the mutation lists are JSON files; **they are not in the repository, and this file is the record.** The harness does not check that a spec path it is given
exists (Vitest silently ignores a filter that matches nothing), which is how B4-1 "survived" on the first run: I had typed `src/core/router/guards.spec.ts`, and the file is `tests/unit/core/router/guards.spec.ts`. That one
was a mistake in my mutation list, not a hole in the specs; the rerun with the right path is below, and no spec was added for it.

Counts of distinct mutations: B1 19, B2 10, B3 16, B4 26 (23 web, 3 API), B5 4, B6 13 (11 web, 2 API). Survived on first run: B2-9, B4-1 (harness path, above), B4-6, B4-10, B4-18, B4-21, B6-3. All closed.

## B1 + B2 (the visibility scope, the guard, the `is_erased` flag) — API

Run 1. B2-9 SURVIVED: nothing asserted that a LIVE creator is not flagged erased in discovery, so a resource that said `true` for everyone passed. The spec added for it is in the next block.

```
MUTATION B1-1 scope ignores erased creators: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   msg : Failed asserting that two arrays are identical.
   msg : Failed asserting that two arrays are identical.
MUTATION B1-2 scope ignores creators with a pending request: exit=2 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Feature\Modules\Agencies\AgencyCreatorDiscoveryTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Feature\Modules\Agencies\AgencyCreatorDiscoveryTest > it le…
   msg : Failed asserting that two arrays are identical.
   msg : -    0 => 1,
MUTATION B1-3 guard never refuses: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   msg : Expected response status code [422] but received 201.
   msg : Failed asserting that 201 is identical to 422.
MUTATION B1-4 single-row twin ignores a pending request: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Feature\Modules\Agencies\AgencyCreatorDiscoveryTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Feature\Modules\Agencies\AgencyCreatorDiscoveryTest > it do…
   msg : Failed asserting that true is false.
   msg : Expected response status code [404] but received 200.
MUTATION B1-5 single-row twin ignores erased: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   msg : Failed asserting that false is true.
   msg : Expected response status code [404] but received 200.
MUTATION B1-6 invite door does not call the guard: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Expected response status code [422] but received 201.
   msg : Failed asserting that 201 is identical to 422.
MUTATION B1-7 pool-add door does not call the guard: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Expected response status code [422] but received 201.
   msg : Failed asserting that 201 is identical to 422.
MUTATION B1-8 connection-request door does not call the guard: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Expected response status code [422] but received 201.
   msg : Failed asserting that 201 is identical to 422.
MUTATION B1-9 accept-application door does not call the guard: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Expected response status code [422] but received 201.
   msg : Failed asserting that 201 is identical to 422.
MUTATION B1-10 new-thread door does not call the guard: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Expected response status code [422] but received 201.
   msg : Failed asserting that 201 is identical to 422.
MUTATION B1-11 discovery pool does not hide them: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that two arrays are identical.
   msg : -Array &0 []
MUTATION B1-12 new-conversation contact list shows them: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that two arrays are identical.
   msg : -Array &0 []
MUTATION B1-13 job-posted fan-out invites them: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that two arrays are identical.
   msg : Failed asserting that two arrays are identical.
MUTATION B1-14 digest mail still goes to them: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that two arrays are identical.
   msg : -Array &0 []
MUTATION B1-15 nudge still goes to them: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
MUTATION B1-16 campaign-message notice not silenced: exit=1 -> CAUGHT
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that two arrays are identical.
   msg : -Array &0 []
MUTATION B1-17 relationship-message notice not silenced: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that 1 is identical to 0.
   msg : Failed asserting that 1 is identical to 0.
MUTATION B1-18 application notices not silenced: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that 1 is identical to 0.
   msg : Failed asserting that 1 is identical to 0.
MUTATION B1-19 refusal code says why (leaks the pending state): exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   msg : Failed asserting that two strings are identical.
   msg : -'creator.unavailable'
MUTATION B2-1 isErased() always false: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   spec: FAILED  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest >…
   msg : Failed asserting that false is true.
   msg : Failed asserting that false is true.
MUTATION B2-2 isErased() reads a missing column as "not erased": exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest >…
   msg : Failed asserting that false is true.
MUTATION B2-3 shared trait never says erased: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest
   spec: FAILED  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest >…
   msg : Failed asserting that false is true.
MUTATION B2-4 board card drops is_erased: exit=1 -> CAUGHT
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that two arrays are identical.
   msg : -Array &0 []
MUTATION B2-5 assignment resource drops is_erased: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that null is true.
   msg : Failed asserting that two arrays are identical.
MUTATION B2-6 creator detail drops is_erased: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that null is true.
   msg : Failed asserting that two arrays are identical.
MUTATION B2-7 roster row drops is_erased: exit=2 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Creators\CreatorUnavailableToAgenciesTest
   spec: FAIL  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Creators\CreatorUnavailable…  ErrorException
   msg : Failed asserting that null is true.
   msg : Failed asserting that two arrays are identical.
MUTATION B2-8 thread rollup drops is_erased: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest
   spec: FAIL  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest
   spec: FAILED  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest >…
   spec: FAILED  Tests\Unit\Modules\Creators\AgencyCreatorSurfacesTest > it requir…
   msg : Failed asserting that two arrays are identical.
   msg : -    0 => false,
MUTATION B2-9 discovery resource says erased for everyone: exit=0 -> SURVIVED!
restored-clean: True
```

Run 2 (B2-9 again, after `does not flag a live, discoverable creator as erased in discovery` was added; B2-10 is the same mutation on the public-profile resource). `restored-clean: False` here is because the new spec was not yet committed, not a leftover mutation.

```
MUTATION B2-9 discovery resource says erased for everyone (rerun after the spec was added): exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest
   spec: FAILED  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest >…
   msg : Failed asserting that true is false.
MUTATION B2-10 public profile resource says erased for everyone: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest
   spec: FAILED  Tests\Feature\Modules\Agencies\ErasedCreatorAgencySurfacesTest >…
   msg : Failed asserting that true is false.
restored-clean: False
```

## B3 (the agency label, `creator.unavailable` in the flows) — web

```
MUTATION B3-1 helper returns the stored name for an erased creator: exit=1 -> CAUGHT
   spec: FAIL  src/core/creators/creatorLabel.spec.ts > creatorLabel > says "Deleted creator" for an erased creator, even if a name leaked into the payload
   spec: FAIL  src/modules/boards/components/BoardCard.spec.ts > BoardCard > says "Deleted creator" with no photo for an erased creator, whatever the payload carries
   spec: FAIL  src/modules/campaigns/components/ApplicationsTab.spec.ts > ApplicationsTab > shows "Deleted creator" with no photo for an erased applicant
   spec: FAIL  src/modules/campaigns/components/InviteCreatorsDialog.spec.ts > InviteCreatorsDialog (campaign invite picker) > labels an erased roster row "Deleted creator" and ne
   msg : AssertionError: expected 'Leaked Name' to be 'Deleted creator' // Object.is equality
   msg : AssertionError: expected 'Leaked Name' to be 'Deleted creator' // Object.is equality
MUTATION B3-2 helper serves the avatar of an erased creator: exit=1 -> CAUGHT
   spec: FAIL  src/components/CreatorIdentityAvatar.spec.ts > CreatorIdentityAvatar > never draws an image for an erased creator and shows ?
   spec: FAIL  src/core/creators/creatorLabel.spec.ts > creatorAvatarUrl / creatorAvatarAlt > never returns an image for an erased creator
   spec: FAIL  src/modules/boards/components/BoardCard.spec.ts > BoardCard > says "Deleted creator" with no photo for an erased creator, whatever the payload carries
   spec: FAIL  src/modules/campaigns/components/ApplicationsTab.spec.ts > ApplicationsTab > shows "Deleted creator" with no photo for an erased applicant
   msg : AssertionError: expected true to be false // Object.is equality
   msg : AssertionError: expected 'https://x/a.png' to be null
MUTATION B3-3 helper gives an erased creator a real initial: exit=1 -> CAUGHT
   spec: FAIL  src/components/CreatorIdentityAvatar.spec.ts > CreatorIdentityAvatar > never draws an image for an erased creator and shows ?
   spec: FAIL  src/core/creators/creatorLabel.spec.ts > creatorInitial > is ? for erased, nameless or missing
   msg : AssertionError: expected 'L' to be '?' // Object.is equality
   msg : AssertionError: expected 'L' to be '?' // Object.is equality
MUTATION B3-4 helper keeps the alt text of an erased creator: exit=1 -> CAUGHT
   spec: FAIL  src/core/creators/creatorLabel.spec.ts > creatorAvatarUrl / creatorAvatarAlt > never returns an image for an erased creator
   msg : AssertionError: expected 'Leaked Name' to be '' // Object.is equality
MUTATION B3-5 helper never reads is_erased: exit=1 -> CAUGHT
   spec: FAIL  src/components/CreatorIdentityAvatar.spec.ts > CreatorIdentityAvatar > never draws an image for an erased creator and shows ?
   spec: FAIL  src/core/creators/creatorLabel.spec.ts > creatorLabel > says "Deleted creator" for an erased creator, even if a name leaked into the payload
   spec: FAIL  src/core/creators/creatorLabel.spec.ts > creatorLabel > treats only a literal true as erased
   spec: FAIL  src/core/creators/creatorLabel.spec.ts > creatorInitial > is ? for erased, nameless or missing
   msg : AssertionError: expected true to be false // Object.is equality
   msg : AssertionError: expected 'Leaked Name' to be 'Deleted creator' // Object.is equality
MUTATION B3-6 board card reads the name directly (bypasses the helper): exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/creator-label-single-reader.spec.ts > creator name and avatar have one reader > no agency source reads display_name or avatar_url in code
   spec: FAIL  src/modules/boards/components/BoardCard.spec.ts > BoardCard > says "Deleted creator" with no photo for an erased creator, whatever the payload carries
   msg : AssertionError: expected [ Array(1) ] to deeply equal []
   msg : AssertionError: expected 'Leaked Name' to be 'Deleted creator' // Object.is equality
MUTATION B3-7 applications tab reads the name directly: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/creator-label-single-reader.spec.ts > creator name and avatar have one reader > no agency source reads display_name or avatar_url in code
   spec: FAIL  src/modules/campaigns/components/ApplicationsTab.spec.ts > ApplicationsTab > shows "Deleted creator" with no photo for an erased applicant
   msg : AssertionError: expected [ Array(1) ] to deeply equal []
   msg : AssertionError: expected '?Leaked NamePendingApplied Jul 1, 202…' to contain 'Deleted creator'
MUTATION B3-8 roster table reads the name directly: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/creator-label-single-reader.spec.ts > creator name and avatar have one reader > no agency source reads display_name or avatar_url in code
   spec: FAIL  src/modules/roster/pages/CreatorRosterPage.spec.ts > CreatorRosterPage (Sprint 4 Chunk 5) > shows "Deleted creator" in the name cell for an erased creator
   msg : AssertionError: expected [ Array(1) ] to deeply equal []
   msg : AssertionError: expected 'Leaked Name' to be 'Deleted creator' // Object.is equality
MUTATION B3-9 invite picker reads the name directly: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/creator-label-single-reader.spec.ts > creator name and avatar have one reader > no agency source reads display_name or avatar_url in code
   spec: FAIL  src/modules/campaigns/components/InviteCreatorsDialog.spec.ts > InviteCreatorsDialog (campaign invite picker) > labels an erased roster row "Deleted creator" and ne
   msg : AssertionError: expected [ Array(1) ] to deeply equal []
   msg : AssertionError: expected '?Leaked Name GB' to contain 'Deleted creator'
MUTATION B3-10 pool picker reads the name directly: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/creator-label-single-reader.spec.ts > creator name and avatar have one reader > no agency source reads display_name or avatar_url in code
   spec: FAIL  src/modules/pools/components/AddCreatorsToPoolDialog.spec.ts > AddCreatorsToPoolDialog (pool-side add) > labels an erased roster row "Deleted creator" and never pri
   msg : AssertionError: expected [ Array(1) ] to deeply equal []
   msg : AssertionError: expected '?Leaked Name GB' to contain 'Deleted creator'
MUTATION B3-11 invite counts creator.unavailable as a blacklist: exit=1 -> CAUGHT
   spec: FAIL  src/modules/campaigns/components/InviteCreatorsDialog.spec.ts > InviteCreatorsDialog (campaign invite picker) > words a creator.unavailable refusal as such, not as
   msg : AssertionError: expected 'Invited 1. Skipped 1 (blacklisted), 0…' to contain 'This creator is no longer available.'
MUTATION B3-12 invite summary does not mention the unavailable creator: exit=1 -> CAUGHT
   spec: FAIL  src/modules/campaigns/components/InviteCreatorsDialog.spec.ts > InviteCreatorsDialog (campaign invite picker) > words a creator.unavailable refusal as such, not as
   msg : AssertionError: expected 'Invited 1. Skipped 0 (blacklisted), 0…' to contain 'This creator is no longer available.'
MUTATION B3-13 pool add shows the generic failure for creator.unavailable: exit=1 -> CAUGHT
   spec: FAIL  src/modules/pools/components/AddCreatorsToPoolDialog.spec.ts > AddCreatorsToPoolDialog (pool-side add) > says the creator is no longer available when the add is ref
   msg : AssertionError: expected 'Failed to add creators to the pool.' to contain 'This creator is no longer available.'
MUTATION B3-14 accept shows the generic refusal for creator.unavailable: exit=1 -> CAUGHT
   spec: FAIL  src/modules/campaigns/components/AcceptApplicationDialog.spec.ts > AcceptApplicationDialog > says the creator is no longer available when they deleted their account
   msg : AssertionError: expected 'Something went wrong. Please try agai…' to contain 'This creator is no longer available.'
MUTATION B3-15 the avatar component bypasses the helper: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/creator-label-single-reader.spec.ts > creator name and avatar have one reader > no agency source reads display_name or avatar_url in code
   spec: FAIL  src/components/CreatorIdentityAvatar.spec.ts > CreatorIdentityAvatar > never draws an image for an erased creator and shows ?
   spec: FAIL  src/modules/campaigns/components/ApplicationsTab.spec.ts > ApplicationsTab > shows "Deleted creator" with no photo for an erased applicant
   msg : AssertionError: expected [ Array(1) ] to deeply equal []
   msg : AssertionError: expected true to be false // Object.is equality
MUTATION B3-16 app.creatorErased missing from en: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/i18n-used-keys-resolve.spec.ts > main i18n — every t() call site resolves against en (used-key coverage) > every static t('dotted.key') call
   spec: FAIL  src/modules/boards/components/BoardCard.spec.ts > BoardCard > says "Deleted creator" with no photo for an erased creator, whatever the payload carries
   spec: FAIL  src/modules/campaigns/components/ApplicationsTab.spec.ts > ApplicationsTab > shows "Deleted creator" with no photo for an erased applicant
   spec: FAIL  src/modules/campaigns/components/InviteCreatorsDialog.spec.ts > InviteCreatorsDialog (campaign invite picker) > labels an erased roster row "Deleted creator" and ne
   msg : Error: 1 t() call(s) reference a key missing from en:
   msg : AssertionError: expected 'app.creatorErased' to be 'Deleted creator' // Object.is equality
restored-clean: True
```

## B4 (Security page, dialog, store, routes, flag) and B5 (public page, support address) — web

Run 1. B4-1 (harness path typo, see the top), B4-6, B4-10, B4-18 and B4-21 SURVIVED. The specs added are in `SecurityPage.spec.ts`: "keeps the button disabled when ONLY the tick is missing, or is taken back", "sends ONE request while one is in flight", "a reopened dialog starts clean", "403 account_deletion.not_available: shown in the reader's language through the error mapper".

```
MUTATION B4-1 guard ignores the flag: exit=0 -> SURVIVED!
MUTATION B4-2 security route loses the flag guard: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/creator-routes-guard.spec.ts > creator-shell routes — requireAuth registration (AH-056, C3) > the Security route is flag-gated: requireAuth
   msg : AssertionError: expected [ 'requireAuth' ] to deeply equal [ 'requireAuth', …(1) ]
MUTATION B4-3 security route loses requireAuth: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/creator-routes-guard.spec.ts > creator-shell routes — requireAuth registration (AH-056, C3) > every creator-shell route declares requireAuth
   spec: FAIL  tests/unit/architecture/creator-routes-guard.spec.ts > creator-shell routes — requireAuth registration (AH-056, C3) > the Security route is flag-gated: requireAuth
   msg : AssertionError: creator-shell route creator.security is missing requireAuth: expected [ 'requireAccountDeletionEnabled' ] to include 'requireAuth'
   msg : AssertionError: expected [ 'requireAccountDeletionEnabled' ] to deeply equal [ 'requireAuth', …(1) ]
MUTATION B4-4 layout shows Security whatever the flag says: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/layouts/CreatorDashboardLayout.spec.ts > CreatorDashboardLayout — Security entries (AH-104b-del B4) > web: no Security entry when the flag is o
   spec: FAIL  src/modules/creators/layouts/CreatorDashboardLayout.spec.ts > CreatorDashboardLayout — Security entries (AH-104b-del B4) > web: no Security entry when the flag is a
   spec: FAIL  src/modules/creators/layouts/CreatorDashboardLayout.spec.ts > CreatorDashboardLayout — Security entries (AH-104b-del B4) > native: no Security row when the flag is
   msg : AssertionError: expected true to be false // Object.is equality
   msg : AssertionError: expected true to be false // Object.is equality
MUTATION B4-5 button enabled without the typed word: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > keeps the button disabled until the tick, the password and the typed word are all there
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > keeps the button disabled until the tick, the password and the typed word are all there
   msg : AssertionError: expected undefined to be defined
MUTATION B4-6 button enabled without the acknowledgement: exit=0 -> SURVIVED!
MUTATION B4-7 button enabled with an empty password: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > keeps the button disabled until the tick, the password and the typed word are all there
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > keeps the button disabled until the tick, the password and the typed word are all there
   msg : AssertionError: expected undefined to be defined
MUTATION B4-8 button enabled without the 2FA code: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > asks for the 2FA code ONLY when two-factor is enrolled
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > asks for the 2FA code ONLY when two-factor is enrolled
   msg : AssertionError: expected undefined to be defined
MUTATION B4-9 button enabled during the 429 countdown: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > 429: counts down and keeps the button disabled until the wait is over
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > 429: counts down and keeps the button disabled until the wait is over
   msg : AssertionError: expected undefined to be defined
MUTATION B4-10 button enabled while a request is in flight: exit=0 -> SURVIVED!
MUTATION B4-11 dialog sends acknowledged: false: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > on success: sends the body, signs the user out locally, and goes to sign-in with the date
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > on success: sends the body, signs the user out locally, and goes to sign-in with the date
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > omits mfa_code when two-factor is not enrolled
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > omits mfa_code when two-factor is not enrolled
   msg : AssertionError: expected "spy" to be called with arguments: [ { password: 'Pa$$w0rd!12', …(2) } ]
   msg : AssertionError: expected "spy" to be called with arguments: [ { password: 'Pa$$w0rd!12', …(1) } ]
MUTATION B4-12 success lands without the scheduled-deletion reason: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > on success: sends the body, signs the user out locally, and goes to sign-in with the date
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > on success: sends the body, signs the user out locally, and goes to sign-in with the date
   msg : AssertionError: expected { reason: 'done', …(1) } to deeply equal { reason: 'deletion_scheduled', …(1) }
MUTATION B4-13 success path leaves the user in the store: exit=1 -> CAUGHT
   spec: FAIL  src/modules/auth/stores/useAuthStore.accountDeletion.spec.ts > requestAccountDeletion > web: returns the dates, clears the user, and never calls logout
   spec: FAIL  src/modules/auth/stores/useAuthStore.accountDeletion.spec.ts > requestAccountDeletion > native: forgets the push record locally, clears the token, makes NO push or
   spec: FAIL  src/modules/auth/stores/useAuthStore.accountDeletion.spec.ts > requestAccountDeletion > native: a failing local cleanup still ends with the user cleared
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > on success: sends the body, signs the user out locally, and goes to sign-in with the date
   msg : AssertionError: expected { …(3) } to be null
   msg : AssertionError: expected { …(3) } to be null
MUTATION B4-14 success path keeps the native bearer token: exit=1 -> CAUGHT
   spec: FAIL  src/modules/auth/stores/useAuthStore.accountDeletion.spec.ts > requestAccountDeletion > native: forgets the push record locally, clears the token, makes NO push or
   msg : AssertionError: expected [ 'api.requestAccountDeletion', …(1) ] to deeply equal [ 'api.requestAccountDeletion', …(2) ]
MUTATION B4-15 success path keeps the device push token locally: exit=1 -> CAUGHT
   spec: FAIL  src/modules/auth/stores/useAuthStore.accountDeletion.spec.ts > requestAccountDeletion > native: forgets the push record locally, clears the token, makes NO push or
   msg : AssertionError: expected [ 'api.requestAccountDeletion', …(1) ] to deeply equal [ 'api.requestAccountDeletion', …(2) ]
MUTATION B4-16 unknown blocker reason shown as a raw key: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > blocked (422): names each reason and the support address
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > blocked (422): names each reason and the support address
   msg : AssertionError: expected 'A payment on your account is still be…' to contain 'Something on your account still needs…'
MUTATION B4-17 429 not turned into a countdown: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > 429: counts down and keeps the button disabled until the wait is over
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > 429: counts down and keeps the button disabled until the wait is over
   msg : AssertionError: expected 'Too many requests. Please try again i…' to contain '3 seconds'
MUTATION B4-18 a typed password outlives the dialog: exit=0 -> SURVIVED!
MUTATION B4-19 typed word compared raw (no normalisation): exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > keeps the button disabled until the tick, the password and the typed word are all there
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > keeps the button disabled until the tick, the password and the typed word are all there
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > on success: sends the body, signs the user out locally, and goes to sign-in with the date
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > on success: sends the body, signs the user out locally, and goes to sign-in with the date
   msg : AssertionError: expected '' to be undefined
   msg : AssertionError: expected "spy" to be called with arguments: [ { password: 'Pa$$w0rd!12', …(2) } ]
MUTATION B4-20 any non-empty word accepted: exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > keeps the button disabled until the tick, the password and the typed word are all there
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > keeps the button disabled until the tick, the password and the typed word are all there
   spec: FAIL  src/modules/creators/security/deleteWord.spec.ts > the confirmation word, per locale > bg: exists, differs from the button label, tolerates the keyboard
   spec: FAIL  src/modules/creators/security/deleteWord.spec.ts > the confirmation word, per locale > hr: exists, differs from the button label, tolerates the keyboard
   msg : AssertionError: expected undefined to be defined
   msg : AssertionError: expected true to be false // Object.is equality
MUTATION B4-21 error mapper ignores the account_deletion. prefix: exit=0 -> SURVIVED!
MUTATION B4-22 sign-in banner never shown: exit=1 -> CAUGHT
   spec: FAIL  src/modules/auth/pages/SignInPage.spec.ts > SignInPage > shows the deletion-scheduled banner, with the date, after the delete-account dialog (AH-104b-del)
   msg : AssertionError: expected false to be true // Object.is equality
MUTATION B4-23 disable-2FA goes back to the old non-existent route: exit=1 -> CAUGHT
   spec: FAIL  src/modules/auth/pages/DisableTotpPage.spec.ts > DisableTotpPage > happy path (creator): calls disableTotp with password + mfa_code, goes to creator.security
   msg : AssertionError: expected "push" to be called with arguments: [ { name: 'creator.security' } ]
MUTATION B5-1 public page behind requireAuth: exit=1 -> CAUGHT
   spec: FAIL  src/modules/auth/pages/DeleteAccountInfoPage.spec.ts > /delete-account (public page) > is a public route: auth layout, no guards, and not under /creator/
   msg : AssertionError: expected [ 'requireAuth' ] to deeply equal []
MUTATION B5-2 public page moved under /creator/ (captured by the app link scope): exit=1 -> CAUGHT
   spec: FAIL  src/modules/auth/pages/DeleteAccountInfoPage.spec.ts > /delete-account (public page) > is a public route: auth layout, no guards, and not under /creator/
   msg : AssertionError: expected '/creator/delete-account' to be '/delete-account' // Object.is equality
MUTATION B5-3 support address typed inline on the public page: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/support-address-single-source.spec.ts > the support address has one home per runtime > the literal appears only in the config module
   msg : AssertionError: expected [ Array(1) ] to deeply equal []
MUTATION B5-4 support default changed: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/support-address-single-source.spec.ts > the support address has one home per runtime > the config module reads VITE_SUPPORT_EMAIL, and the e
   msg : AssertionError: expected '/**\n * The ONE place the web apps kn…' to contain 'support@catalyst-growth.com'
restored-clean: True
```

Run 2 (the five survivors again)

```
MUTATION B4-1 guard ignores the flag (rerun after the spec was added): exit=1 -> CAUGHT
   spec: FAIL  tests/unit/core/router/guards.spec.ts > requireAccountDeletionEnabled (AH-104b-del B4) > sends the creator to the dashboard when the feature is off
   spec: FAIL  tests/unit/core/router/guards.spec.ts > requireAccountDeletionEnabled (AH-104b-del B4) > treats a response without `features` (an older payload) as off
   msg : AssertionError: expected null to deeply equal { name: 'creator.dashboard' }
   msg : AssertionError: expected null to deeply equal { name: 'creator.dashboard' }
MUTATION B4-6 button enabled without the acknowledgement (rerun after the spec was added): exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > keeps the button disabled when ONLY the tick is missing, or is taken back
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > keeps the button disabled when ONLY the tick is missing, or is taken back
   msg : AssertionError: expected undefined to be defined
MUTATION B4-10 button enabled while a request is in flight (rerun after the spec was added): exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > sends ONE request while one is in flight (the button is disabled, a second click does nothin
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > sends ONE request while one is in flight (the button is disabled, a second click does not
   msg : AssertionError: expected undefined to be defined
MUTATION B4-18 a typed password outlives the dialog (rerun after the spec was added): exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > a reopened dialog starts clean: the password typed before is gone
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > a reopened dialog starts clean: the password typed before is gone
   msg : AssertionError: expected 'Pa$$w0rd!12' to be '' // Object.is equality
MUTATION B4-21 error mapper ignores the account_deletion. prefix (rerun after the spec was added): exit=1 -> CAUGHT
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (web) > 403 account_deletion.not_available: shown in the reader's language through the error mapper
   spec: FAIL  src/modules/creators/pages/SecurityPage.spec.ts > Security page (native) > 403 account_deletion.not_available: shown in the reader's language through the error mapp
   msg : AssertionError: expected 'Something went wrong. Please try agai…' to be 'Account deletion isn\'t available for…' // Object.is equality
restored-clean: True
```

## B4 — API (the `features.account_deletion` flag on the user)

```
MUTATION B4-A1 API: every user gets the flag (agency and platform admins too): exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Identity\UserFeaturesAccountDeletionTest
   spec: FAILED  Tests\Feature\Modules\Identity\UserFeaturesAccountDeletionTest >…
   msg : Failed asserting that true is identical to false.
   msg : Failed asserting that true is identical to false.
MUTATION B4-A2 API: the flag is ignored (always true for creators): exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Identity\UserFeaturesAccountDeletionTest
   spec: FAILED  Tests\Feature\Modules\Identity\UserFeaturesAccountDeletionTest >…
   msg : Failed asserting that true is identical to false.
   msg : Failed asserting that true is identical to false.
MUTATION B4-A3 API: the flag is never true: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Identity\UserFeaturesAccountDeletionTest
   spec: FAILED  Tests\Feature\Modules\Identity\UserFeaturesAccountDeletionTest >…
   msg : Failed asserting that false is identical to true.
   msg : Failed asserting that false is identical to true.
restored-clean: True
```

## B6 (the erased-creator cancellation notice) — web

Run 1. B6-3 SURVIVED: the unknown-reason spec asserted `toContain('Budget was pulled.')`, which a missing-key echo (`notifications.center.reasons.Budget was pulled.`) also satisfies. The assertion is now an exact string.

```
MUTATION B6-1 type missing from the live registry: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/i18n-notifications-parity.spec.ts > i18n notifications.* — en/pt/it parity + only-8-templated invariant > every live type maps to a bespoke
   spec: FAIL  tests/unit/architecture/i18n-notifications-parity.spec.ts > notifications prefs role-partition — single live-set source of truth > the always-on types are live but
   spec: FAIL  src/modules/notifications/notificationDestination.registry.spec.ts > registry ⇄ contract parity > the registry and the contract list exactly the same types
   spec: FAIL  src/modules/notifications/notificationDestination.registry.spec.ts > registry ⇄ contract parity > assignment.cancelled: declares a destination
   msg : AssertionError: expected false to be true // Object.is equality
   msg : AssertionError: expected false to be true // Object.is equality
MUTATION B6-2 reason code shown raw: exit=1 -> CAUGHT
   spec: FAIL  src/modules/notifications/components/NotificationCenter.spec.ts > NotificationCenter — localized render per live type > assignment.cancelled (the erased-creator not
   msg : AssertionError: expected 'Reason: creator_account_erased' to contain 'The creator deleted their account.'
MUTATION B6-3 every reason forced through i18n (free text lost): exit=0 -> SURVIVED!
MUTATION B6-4 tap opens the wrong tab: exit=1 -> CAUGHT
   spec: FAIL  src/modules/notifications/templates.spec.ts > assignment.cancelled — the erased-creator notice (AH-104b-del B6) > opens the campaign on its creators tab
   spec: FAIL  src/modules/notifications/components/NotificationCenter.spec.ts > NotificationCenter — localized render per live type > assignment.cancelled (the erased-creator not
   msg : AssertionError: expected { kind: 'route', …(3) } to deeply equal { kind: 'route', …(3) }
   msg : AssertionError: expected '/campaigns/01J9ZK3Q8M5T7WXYCAMPAGNAAA…' to be '/campaigns/01J9ZK3Q8M5T7WXYCAMPAGNAAA…' // Object.is equality
MUTATION B6-5 notice offered as a toggle: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/i18n-notifications-parity.spec.ts > notifications prefs role-partition — single live-set source of truth > creator + agency prefs types part
   spec: FAIL  tests/unit/architecture/i18n-notifications-parity.spec.ts > notifications prefs role-partition — single live-set source of truth > the always-on types are live but
   spec: FAIL  tests/unit/architecture/i18n-notifications-parity.spec.ts > notifications prefs role-partition — single live-set source of truth > the known role split is honest (c
   spec: FAIL  src/modules/notifications/templates.spec.ts > assignment.cancelled — the erased-creator notice (AH-104b-del B6) > reaches the agency only, and is always-on (no togg
   msg : AssertionError: expected [ 'assignment.cancelled', …(19) ] to deeply equal [ …(19) ]
   msg : AssertionError: expected [ 'assignment.draft_submitted', …(5) ] to not include 'assignment.cancelled'
MUTATION B6-6 notice registered for the creator, not the agency: exit=1 -> CAUGHT
   spec: FAIL  src/modules/notifications/notificationDestination.registry.spec.ts > recipient parity — a notification never opens the other app > assignment.cancelled: the destina
   spec: FAIL  src/modules/notifications/templates.spec.ts > assignment.cancelled — the erased-creator notice (AH-104b-del B6) > reaches the agency only, and is always-on (no togg
   msg : AssertionError: assignment.cancelled → campaigns.detail: expected false to be true // Object.is equality
   msg : AssertionError: expected 'creator' to be 'agency' // Object.is equality
MUTATION B6-7 template names the creator: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/i18n-locale-parity.spec.ts > i18n locale parity — en SOT across every namespace (S7) > every message carries the same {named} placeholders a
   spec: FAIL  src/modules/notifications/templates.spec.ts > assignment.cancelled — the erased-creator notice (AH-104b-del B6) > the reason code has a localised line, and the temp
   spec: FAIL  src/modules/notifications/components/NotificationCenter.spec.ts > NotificationCenter — localized render per live type > assignment.cancelled (the erased-creator not
   msg : AssertionError: placeholder drift from en:
   msg : AssertionError: expected '{creator_name}: an assignment on {cam…' not to contain '{creator_name}'
MUTATION B6-8 one locale lacks the template: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/i18n-locale-parity.spec.ts > i18n locale parity — en SOT across every namespace (S7) > every locale exposes EXACTLY the en key-set, file by
   spec: FAIL  tests/unit/architecture/i18n-notifications-parity.spec.ts > i18n notifications.* — en/pt/it parity + only-8-templated invariant > all rendered locales expose an ide
   msg : AssertionError: keyset drift from en:
   msg : AssertionError: de/notifications.json key-set differs from en: expected [ 'notifications.center.empty', …(81) ] to deeply equal [ 'notifications.center.empty', …(82) ]
MUTATION B6-9 one locale lacks the reason line: exit=1 -> CAUGHT
   spec: FAIL  tests/unit/architecture/i18n-locale-parity.spec.ts > i18n locale parity — en SOT across every namespace (S7) > every locale exposes EXACTLY the en key-set, file by
   spec: FAIL  tests/unit/architecture/i18n-notifications-parity.spec.ts > i18n notifications.* — en/pt/it parity + only-8-templated invariant > all rendered locales expose an ide
   msg : AssertionError: keyset drift from en:
   msg : AssertionError: fr/notifications.json key-set differs from en: expected [ 'notifications.center.empty', …(81) ] to deeply equal [ 'notifications.center.empty', …(82) ]
MUTATION B6-10 contract file drops the type: exit=1 -> CAUGHT
   spec: FAIL  src/modules/notifications/notificationDestination.registry.spec.ts > registry ⇄ contract parity > the registry and the contract list exactly the same types
   msg : AssertionError: expected [ …(22) ] to deeply equal [ …(21) ]
MUTATION B6-11 contract promises a key the destination never reads: exit=1 -> CAUGHT
   spec: FAIL  src/modules/notifications/notificationDestination.registry.spec.ts > registry ⇄ contract parity > assignment.cancelled: reads only ids its emitter writes, and uses
   msg : AssertionError: assignment.cancelled: the contract carries assignment_ulid,campaign_ulid: expected [ 'campaign_ulid' ] to deeply equal [ 'assignment_ulid', 'campaign_ulid' ]
restored-clean: True
```

Run 2 (B6-3 again)

```
MUTATION B6-3 every reason forced through i18n (free text lost) (rerun after the spec was added): exit=1 -> CAUGHT
   spec: FAIL  src/modules/notifications/components/NotificationCenter.spec.ts > NotificationCenter — localized render per live type > assignment.cancelled (the erased-creator not
   msg : AssertionError: expected 'Reason: notifications.center.reasons.…' to be 'Reason: Budget was pulled.' // Object.is equality
restored-clean: True
```

## B6 — API (the emitter, the contract, push eligibility)

```
MUTATION B6-A1 API: the notice leaves out campaign_ulid: exit=1 -> CAUGHT
   spec: FAIL  Tests\Feature\Modules\Notifications\NotificationRouteKeysContractTest
   spec: FAILED  Tests\Feature\Modules\Notifications\NotificationRouteKeysContract…
   msg : Failed asserting that null is identical to '01M5F5TA9SAJK87ETAATBFPD30'.
MUTATION B6-A2 API: the agency notice becomes push-eligible: exit=1 -> CAUGHT
   spec: FAIL  Tests\Unit\Modules\Notifications\PushEligibilityTest
   spec: FAILED  Tests\Unit\Modules\Notifications\PushEligibilityTest > it the tog…
   spec: FAILED  Tests\Unit\Modules\Notifications\PushEligibilityTest > it the two…
   spec: FAILED  Tests\Unit\Modules\Notifications\PushEligibilityTest > it every N…
   msg : Failed asserting that two arrays are identical.
   msg : -    0 => 'assignment.completed_on_approval',
restored-clean: True
```
