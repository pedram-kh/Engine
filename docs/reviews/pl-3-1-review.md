# PL-3.1 review file

## Stage 1 (backend): accepted by Pedram, 2026-11-23

Pushed tip `b097c62b`; CI run `45081699059`, all four jobs green. Accepted differences from the plan:

1. The Creators step state is computed in the creators payload (per market and per wave); `ugc.creators` stays hand-set until PL-3.3.
   `StrategyStepState::STEPS` is not extended.
2. `persona.in_use_by_creators`: a persona that creators are matched to cannot be deleted (422), because the composite foreign key is RESTRICT.
3. The wave-delete guard and the campaign link lock are active whenever creator rows exist (not only while the flag is on).
4. `features.creators_scripts` is always emitted on `/me` (a `false` key while the flag is off), as `strategy` is.
5. The test helpers of the Creators specs carry the `pl31` prefix (they clashed with other files in a full run).
6. B20: the unforced two-process race is not a gate; the forced test (a held, uncommitted wave lock) is.
7. B37 (every creator writer is inside the wave-locked transaction): its break-revert was not isolated (the broken call was fatal).

## Stage 2 (web): settle-first additions

- **A1** `POST .../waves/{wave}/campaign` (`WaveService::createCampaignForWave`): shares `makeWaveCampaign` and the audit rows with
  `createWithNewCampaign`; one transaction on the row-locked wave; refused with `wave.campaign_already_linked`. Linking an existing campaign
  uses the PL-1.2 options list and wave PATCH. Tests: `CreatorsLinkTest`.
- **A2** the picker rule, stated once in the plan (section 1.5). **G2 finding:** the invitation gate (`AssignmentInviteGate`) refuses
  not-approved, non-discoverable, erased and hard-blacklisted creators but **does not check exclusivity** (`AssignmentInviteGate.php:47` is
  the blacklist predicate). Exclusivity is enforced at apply time (`CreatorJobBoardController.php:268`, `BrandExclusivity::isBlocked`) and in the
  job fan-out (`BrandExclusivity.php:136`, `excludeBlockedCreators`); `JobsBoardVisibility` (`JobsBoardVisibility.php:66-78`) is creator-side and deliberately
  leaves exclusivity out. **Decision taken:** the picker applies the exclusivity rule's own predicate (`excludeBlockedCreators`), so a creator
  blocked from this brand by another brand's exclusivity never appears and is refused, as the walk data requires. The earlier read-only
  `exclusive_elsewhere` hint is removed from the candidates payload. Tests: `CreatorsApiTest` B6, one case per exclusion
  (not approved, erased, deletion-scheduled, hard agency, hard brand, exclusive elsewhere), each with a break-revert (rows 32 to 36).
