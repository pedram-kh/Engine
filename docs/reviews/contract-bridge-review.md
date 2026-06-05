# Contract-bridge chunk — Build review

**Status:** Closed

**Reviewed against:** contract-bridge kickoff (D-1..D-10), Sprint 9 eyes-on walk (`accepted` dead-end), `CampaignAssignmentStateMachine::contract()` gate, existing `contracted → draft` chain (Sprint 9 Chunk 1).

This chunk closes the **`accepted → contracted`** gap with a manual two-party flow (agency attach + creator accept). Post-`contracted`, the **existing** draft-submit path is unchanged.

---

## Plan confirmation (locked decisions)

| ID   | Decision                                        | Implementation                                                                                                                                                        |
| ---- | ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D-1  | Agency attach + creator accept (no brand actor) | `CampaignAssignmentContractController::attach` + `CreatorAssignmentContractController::accept`                                                                        |
| D-2  | Accept stops at `contracted`                    | `contract()` only — no `startProducing` in accept endpoint; chain-through test asserts draft submit from `contracted`                                                 |
| D-3  | Storage on `contracts` table                    | `kind=per_campaign`, `subject_type=campaign_assignment`, `body_pdf_path` / `body_markdown`; `Contract::SUBJECT_CAMPAIGN_ASSIGNMENT` added                             |
| D-4  | Flag-ON only                                    | Attach + accept return 422 `assignment.contract_signing_disabled` when OFF                                                                                            |
| D-5  | PDF = signed download link                      | `ContractResource.view_url` (60-min presigned GET)                                                                                                                    |
| D-6  | Attach authz admin/manager/staff                | `CampaignPolicy::attachContract` mirrors `invite`/`review`                                                                                                            |
| D-7  | Two notifications                               | `ContractAttachedMail` (attach → creator), `ContractAcceptedMail` (accept → `invited_by_user_id`)                                                                     |
| D-8  | `requires_per_campaign_contract` informational  | No gating — logged in tech-debt                                                                                                                                       |
| D-9  | Agency upload approach                          | **Small dedicated** `AssignmentContractUploadService` (`agencies/{ulid}/assignments/{assignment_ulid}/contracts/…`) — did **not** generalize `PortfolioUploadService` |
| D-10 | E-sign swap guide                               | [`06-INTEGRATIONS.md` §4.5–4.6](../06-INTEGRATIONS.md); cross-ref in [`feature-flags.md`](../feature-flags.md)                                                        |

**Divergences:** none beyond D-9 choice (documented above).

---

## Backend

| Deliverable                         | Path                                                   |
| ----------------------------------- | ------------------------------------------------------ |
| Agency presigned PDF upload         | `AssignmentContractUploadService`                      |
| Agency attach + media init/complete | `CampaignAssignmentContractController`                 |
| Creator accept                      | `CreatorAssignmentContractController`                  |
| Policy ability                      | `CampaignPolicy::attachContract`                       |
| JSON resource                       | `ContractResource`                                     |
| Mailables                           | `ContractAttachedMail`, `ContractAcceptedMail`         |
| Listener extension                  | `SendAssignmentNotifications` → `AssignmentContracted` |
| Show payload contract embed         | `CreatorAssignmentDraftController::show`               |
| Routes                              | `Campaigns/Routes/api.php`, `Creators/Routes/api.php`  |
| Tests (11)                          | `CampaignAssignmentContractTest.php`                   |

---

## Frontend

| Deliverable             | Path                                                       |
| ----------------------- | ---------------------------------------------------------- |
| Agency attach dialog    | `AttachContractDialog.vue`                                 |
| Creators-tab row action | `CampaignDetailPage.vue` (`accepted && canAttachContract`) |
| Creator accept branch   | `CreatorAssignmentDetailPage.vue`                          |
| API wrappers            | `campaigns.api.ts`, `assignments.api.ts`                   |
| Types                   | `packages/api-client/src/types/campaign.ts`                |
| i18n                    | en/pt/it `app.json` + `creator.json`                       |
| Vitest (+2 cases)       | `CreatorAssignmentDetailPage.spec.ts`                      |

---

## Docs

| File                  | Change                                                  |
| --------------------- | ------------------------------------------------------- |
| `06-INTEGRATIONS.md`  | §4.5 manual flow + §4.6 vendor swap checklist           |
| `feature-flags.md`    | `contract_signing_enabled` row filled + swap-guide link |
| `security/tenancy.md` | Creator-self `contract/accept` allowlist row            |
| `03-DATA-MODEL.md`    | Per-campaign contract wiring note                       |
| `tech-debt.md`        | Eyes-on gap CLOSED + P2 deferrals                       |

---

## Spot-check anchors

- [x] Accept fires `contract()` (`accepted → contracted`), sets `contract_id`, stamps `signed_at`
- [x] Stops at `contracted` — no `startProducing` on accept
- [x] End-to-end chain: accept → contracted → draft submit → `draft_submitted` (`CampaignAssignmentContractTest`)
- [x] Flag OFF → attach + accept unavailable (break-revert tests)
- [x] Creator-self + fail-closed (404 cross-creator, 422 wrong status)
- [x] Attach authz admin/manager/staff; outsider 404
- [x] Storage on existing columns; no schema migration
- [x] Swap guide concrete (binding / flag / UI / webhook gotcha / unchanged lifecycle)

---

## Suggested commit pair (not committed)

1. **Backend:** API controllers, service, resource, mail, routes, lang, tests, backend docs (`06-INTEGRATIONS`, `feature-flags`, `tenancy`, `03-DATA-MODEL`, `tech-debt`).
2. **Frontend:** dialog, detail pages, api-client types, i18n, Vitest, review doc.

---

## Test commands

```bash
cd apps/api && ./vendor/bin/pest tests/Feature/Modules/Campaigns/CampaignAssignmentContractTest.php
cd apps/main && npm run test -- --run src/modules/creators/pages/CreatorAssignmentDetailPage.spec.ts
```
