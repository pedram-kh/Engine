# PL-1.3 inventory: Wave project tracker

**Date:** 2026-11-18. **Branch:** `pl`, from tip `47572aad`. **Status:** inventory only. No code, no migration, no push.
**Scope:** frame 01b in Figma, the wave and brand pages as built, the agency team data, and the patterns a tracker can reuse. Rulings R1 to R10 and P1 to P9 (`production-line-sprints.md` §6) and Pedram's T1 to T6 (below) are binding.

**Pedram's rulings for this chunk (2026-11-18):**

- **T1.** A new wave starts with a ready-made list of steps (the standard production-line steps from frame 01b). The strategist can edit, add and remove. Existing waves get the same list on first open, not by a data-writing migration.
- **T2.** Owners are agency team members only. No client owners (PL-4).
- **T3.** Admin and manager add and remove steps. Admin, manager and staff update a step's status, owner and dates.
- **T4.** Everything is updated by hand in this chunk. Later sprints tick steps automatically, so a step needs an identity that is not its name.
- **T5.** No notifications or reminders to owners. No calendar or timeline view unless frame 01b has one; if it has, report it and leave it out.
- **T6.** Screens with no design (edit a step, add, remove, empty, loading, error) are built from the existing design system and named.

## 0. The designs (Figma MCP, file `lYy90qzKk029vjoVCPExs2`, page "STRATEGY & SCRIPT WRITING")

Frame 01b is 1600 × 1725 px, desktop only. No narrow or mobile variant exists in the file I read.

### 0.1 Pieces read, and pieces not read

| Piece (node id)                                                                                                                    | How read                                                          |
| ---------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| Whole frame node tree, 892 lines (`172:2`)                                                                                         | `get_metadata`. Text node names are cut at 40 characters.         |
| Header: back arrow, title, health pill, two buttons (`172:98`)                                                                     | `get_design_context`, no screenshot                               |
| Wave tabs (`175:5`)                                                                                                                | `get_metadata` only (names and sizes)                             |
| Stream summary: the three stream cards (`175:26`, children `175:27`, `175:75`, `175:113`)                                          | `get_design_context` with screenshot                              |
| Steps panel: header, filter, columns, the three groups, every row, both collapsed lines, the fix card, the Add step line (`176:3`) | `get_design_context` with screenshot (65 KB, read through a file) |
| Right column: Needs attention, Due this week, Team on this wave (`176:4`)                                                          | `get_metadata` only. Strings are cut at 40 characters.            |
| Tracking tooltip (hover) (`176:475`)                                                                                               | `get_metadata` only                                               |
| Frame 01 whole node tree (`13:2`) and the Waves table header (`13:301`)                                                            | `get_metadata` for the tree, `get_design_context` for the header  |
| Frame 01 Waves rows (`13:314` to `13:426`)                                                                                         | `get_metadata` only. Segment fills were not read (see §2).        |

**Not read:** the right column and the tooltip through `get_design_context` (so their colours, spacing and full strings); the wave tabs beyond their names; the sidebar and topbar (they are the shared shell); any state not drawn (empty, loading, error, hover on rows, focus, the row menu opened, the filter chips selected other than "All", the "Show" expansion of a collapsed group, the light theme, any narrow width); prototype links. Frame 01 was not re-read through `get_design_context` beyond the table header; PL-1.1's notes on it stand (`pl-1-1-inventory.md` §0).

### 0.2 Frame 01b in full

**Page shell.** Breadcrumb "Brands › Bolt Food › Wave 7 › Project tracker". Title row: back arrow, "Bolt Food — Wave 7" (26 px), a health pill "At risk · 1 stream delayed" (red). Buttons on the right: "Share status update" (outlined, eye icon) and "Add task" (green, plus icon).

**Wave tabs (six):** Project tracker, Strategy & Scripts, Editing, Statics (with a Figma tag in the brief), Stills, Files. Only the first is PL-1.3's.

**Stream cards, three, in this order** (a card is 401 px wide, 20 px padding, 10 px radius, 1 px border `#2e2e33`; a delayed card has a red border `rgba(255,90,90,0.45)`):

| #   | Stream (swatch)         | Pill                   | Line under the title               | Progress row                               | NEXT STEP                                  | FINAL DELIVERY                          | Footer: owners                           | Footer: link                 |
| --- | ----------------------- | ---------------------- | ---------------------------------- | ------------------------------------------ | ------------------------------------------ | --------------------------------------- | ---------------------------------------- | ---------------------------- |
| 1   | **UGC video** `#3ecf9e` | On track (green)       | "12 videos · 4 creators · SK, RO"  | 13 segments, 9 filled, "9 of 13 steps"     | Internal review · Due today (amber)        | Client approval · Oct 22                | JS, MR: "Jess (strategy) · Marco (edit)" | "Strategy & Scripts" (green) |
| 2   | **Statics** `#8b6cff`   | Delayed · 2 days (red) | "8 statics · 3 sizes · SK, RO"     | 5 segments, 1 filled (red), "1 of 5 steps" | Design v1 · Overdue since Oct 13 (red)     | Client approval · Oct 22 → Oct 24 (red) | LN: "Lena (design)"                      | "Figma file" (purple)        |
| 3   | **Stills** `#f472b6`    | On track (green)       | "24 selects · lifestyle + product" | 6 segments, 3 filled, "3 of 6 steps"       | Retouching & selects · Due tomorrow (grey) | Client approval · Oct 22                | TB: "Tom (stills)"                       | "Shoot folder" (green)       |

Segments are 6 px high, 4 px gap, `#3ecf9e` when done (red `#ff5a5a` on the delayed card), `#2e2e33` when not. **One segment is one step, so the number of segments is the number of steps** (13, 5, 6), not a fixed six. Avatars are 22 px circles with initials, tinted per person.

**Steps panel, "Deliverable steps".** Subtitle: "Every stream for Wave 7 in one place — statuses update as work is completed in the platform". A segmented filter on the right: **All** (selected), UGC, Statics, Stills, **Mine**. Columns (11 px, letter-spaced, grey): **STEP, OWNER, DUE, STATUS, OPEN IN**, then a row menu (three dots) or a chevron.

**Grouping.** One group per stream: a header row (8 px swatch, stream name, "9 of 13 done", and on the right the stream's pill). Inside a group, completed steps collapse into one line with a green tick: "9 completed steps · Kick-off call → Editing (Sep 25 – Oct 12) Show". Open steps follow as rows. Footer row of the table: "+ Add step to a stream".

**Row kinds and statuses.** A row has a round check on the left (hollow while open, a filled green tick when done; the done row's name is greyed), the step name, an owner cell (avatar and first name), a due cell, a status pill, an "open in" link and a row menu.

| Status pill (colour)   | Used on                   | Text colour / tint      |
| ---------------------- | ------------------------- | ----------------------- |
| **Done** (green)       | Static brief & references | `#34c77b` on 15 % green |
| **In progress** (blue) | Retouching & selects      | `#4c8dff` on 15 % blue  |
| **Due today** (amber)  | Internal review (UGC)     | `#f5a524` on 15 % amber |
| **Up next** (grey)     | every other open step     | `#6e6e76` on 15 % grey  |
| **Delayed** (red)      | Design v1 — 8 statics     | `#ff5a5a` on 15 % red   |

The stream pill uses "On track" (green) and "Delayed · 2 days" (red). The header pill uses "At risk · 1 stream delayed".

**How "delayed" is shown.** On the step: a red status pill "Delayed", the due date in red (Oct 13), the row tinted red and a chevron instead of the row menu; the row expands into a **fix card**. On the stream: red border, red pill "Delayed · 2 days", a red progress segment, "Overdue since Oct 13" and a red projected delivery "Oct 22 → Oct 24". On the wave: the "At risk" pill. The frame does not say whether "Delayed" is set by a person or computed. Its evidence points both ways: the step is "Flagged by Lena · Oct 13" (a person), and the figures "2 days", "Overdue since Oct 13" and the "Due today" pill are arithmetic on dates.

**The fix card, "WHAT NEEDS FIXING"** (on the delayed row). Right-aligned "Flagged by Lena · Oct 13". Three labelled lines: **Blocked** ("Waiting on updated app screens from Bolt Food (requested Oct 9, chased Oct 12)"), **Impact** ("Statics internal review slips to Oct 19 → client approval moves from Oct 22 to Oct 24"), **To recover** ("Screens received by Fri Oct 16, or approve using the current app screens from Logo & app screens.zip"). Four buttons: **Chase client** (red, filled), **Use current screens**, **Move due date**, **Reassign**.

**Owner display.** Avatar with initials plus a first name: Jess, Marco, Lena, Tom. Client-facing steps show an avatar "AK" and the word **Client**.

**Date format.** "Today", "Tomorrow", "Mon, Oct 19" (within the coming week), "Oct 22", "Oct 9", and a range "Oct 16 → 19" (start → due). The collapsed line uses "Sep 25 – Oct 12". No year is shown.

**Open in column.** "Footage review", "Client link", "Figma", "Shoot folder", each with an external-link icon. The targets are things that do not exist yet (footage review, a client link, a Figma file, a shoot folder).

**Right column (read as metadata only):**

- **Needs attention**, "2 items": two cards (STATICS "Design v1 · 2 days overdue", "Waiting on app screens from client", link "View fix"; UGC VIDEO "Internal review · due today", "5 of 12 videos reviewed", link "Open review").
- **Due this week**, "Oct 15 – 19": steps grouped by day (Thu 15, Fri 16, Mon 19), each with a stream chip. This is a **list grouped by day, not a calendar grid**.
- **Team on this wave**, "Capacity": Jess (Strategist · all streams · 1 wave this week, "Assigned"), Marco (Editor · UGC · 3 waves this week, "Floating"), Lena (Designer · Statics · 2 waves), Tom (Stills · Photography · 2 waves). Roles and "capacity" are not data the system has.

**Tooltip (hover on the stream pill):** "Statics · 2 days behind", "Updated 1h ago", and three bullets (brief done Oct 9; Design v1 overdue, blocked on client app screens; client approval at risk Oct 22 → Oct 24), and "Click to see what needs fixing →".

**Calendar or timeline view: none.** The frame has no calendar, Gantt or timeline. The "Due this week" list and the range "Oct 16 → 19" are the closest things; the list is reported here and left out (see the plan, §9).

### 0.3 The exact step names, by stream

The frame is a mock-up: it has 24 steps (13 + 5 + 6) and names 16 of them. **The other 8 are not in the file**: they are inside two collapsed lines that give only the first and the last name.

**UGC video, 13 steps.** Step 1 of 9 completed: "Kick-off call". Step 9 of 9 completed: "Editing" (the ones in between are **not named in the frame**). Then four open steps, in order: **Internal review**, **1st editing feedback**, **2nd editing feedback**, **Client approval**.

**Statics, 5 steps.** All five are drawn, in this order: **Static brief & references** (Done), **Design v1 — 8 statics** (Delayed; the "8 statics" is the wave's quantity, so the standard name is "Design v1"), **Internal review**, **Client feedback round**, **Final export & resizes**. The group subtitle also says "Figma linked".

**Stills, 6 steps.** 3 completed steps, from "Shot list" to "Shoot day" (the middle one is **not named**). Then three open steps: **Retouching & selects**, **Internal review**, **Client approval**.

So: **8 step names are unknown**: UGC steps 2 to 8 (seven) and Stills step 2 (one). The plan proposes names for these and flags them for Pedram (`pl-1-3-plan.md` §1.4, question Q2).

## 1. Frame 01's six Progress segments and frame 01b

Read from frame 01 (`13:2`): the Progress cell of each Waves row is **six segments and a word**: Wave 7 "Scripts", Wave 8 "USPs", Wave 6 and Wave 5 "Complete". The status pill beside it is Client review, Draft, Approved, Approved. The word is **the name of the current step**, or "Complete". The design review explains the six: the Strategy & Scripts workspace is a **stepper of six steps**, each with its own status (`new-sections-design-review.md` line 80, and frames 02 to 08: **USPs, Personas, Messaging matrix, Concepts, Creators, Scripts**).

Therefore **frame 01's six segments are the six strategy steps, not the three streams of frame 01b and not the tracker steps.** Frame 01b's own progress is one segment per step, different numbers per stream (13, 5, 6). The two frames do not agree on what a segment is.

What PL-1.1 built: six empty segments and "Not started" for every wave (`WaveProgressSegments.vue`, used by `BrandWavesPanel.vue` and the wave page's `wave-field-progress`).

What "Not started" becomes: see the plan's progress rule (§1.6) and question Q1. The inventory finding is that **the design gives no rule that turns tracker steps into six segments**; any rule is a decision.

## 2. The wave page today (`WaveDetailPage.vue`, 185 lines)

- Route `/brands/:ulid/waves/:n` (name `waves.detail`, `apps/main/src/modules/auth/routes.ts`). On mount, if `features.waves` is not true the page does `router.replace` to the brand page and loads nothing (lines 56 to 61). Otherwise it calls `wavesApi.show`.
- Shown today: page header with back link and a status pill (`wave-status`), an **Edit** button for admin and manager (`canManage`: `agency_admin` or `agency_manager`, lines 10 to 12), and one details card with fields: brand (link), go-live, **progress** (`wave-field-progress`, the six empty segments), markets, campaign (link and the "locked" note, PL-1.2), created by, updated. Skeleton, error alert and the edit dialog exist.
- **Stays:** the header, the Edit dialog, the details card (it carries the campaign link and its lock, P4 and P5, which frame 01b does not draw), the flag redirect, the loading and error states.
- **Moves or changes:** the progress field inside the card is fed by the tracker rule instead of being a placeholder. The stream cards and the steps table are **new blocks below** the details card. Frame 01b has no details card; the plan recommends keeping it above the tracker (question Q9).
- Not read in this inventory: `WaveDetailPage.spec.ts` beyond its existence.

## 3. API side today (Waves module)

- Routes (`apps/api/app/Modules/Waves/Routes/api.php`): the standard tenant stack (`auth:web`, `tenancy.agency`, `tenancy`) under `agencies/{agency}`: `GET/POST brands/{brand}/waves`, `GET brands/{brand}/waves/campaign-options`, `GET/PATCH brands/{brand}/waves/{wave}`, and the three campaign-side routes (PL-1.2). `{wave}` is a plain ulid, looked up in the controller so a soft-deleted wave is a 404.
- Every controller action starts with the `waves_enabled` check (404 `not_found`).
- `Wave` (`BelongsToAgency`, `HasUlid`, `SoftDeletes`): `agency_id`, `brand_id`, `campaign_id`, `name`, `go_live_on`, `markets` (jsonb), `status` (`WaveStatus`: draft only is built), `created_by_user_id`.
- `WavePolicy`: `viewAny` and `view` for any role in the current agency; `create` and `update` for admin and manager (`CurrentAgencyRole::canManage`).
- Audit: `WaveCreated`, `WaveUpdated` with literal keys (`AuditPayloadKeysGuardTest` reads them statically; Erasure decides each key).
- Flag: `WavesEnabled::NAME = 'waves_enabled'`, default OFF, a Closure default.

## 4. Team members

- **Where listed.** `GET agencies/{agency}/members` (`MembershipController::index`, route `agencies.members.index`): "any agency member may list members" (`Routes/api.php`, comment above the route). Paginated (default 25, max 100), filters `role`, `search`, `sort`. It reads `AgencyMembership` (table `agency_users`, soft deletes) where `agency_id` matches and `deleted_at` is null, with the `user` loaded. The web wrapper is `apps/main/src/modules/agency-users/api/members.api.ts`.
- **Roles:** `agency_admin`, `agency_manager`, `agency_staff` (`AgencyRole`). A member counts only with `accepted_at` set and `deleted_at` null (`CurrentAgencyRole::for`).
- **How a member is referenced by ulid.** Users have a `ulid`. The existing owner pointer on a creator relation (AH-119) sends and receives the **user's ulid** on the wire and stores the internal `owner_user_id` (`AgencyCreatorOwnerController`: `owner_user_id` string up to 40 characters, resolved with `User::where('ulid', …)`). It checks the new owner is an **active member of this agency**: accepted, not deleted. Otherwise 422 `owner.not_a_member`. This is the model for a step owner.
- **When a member is removed.** I found **no route that removes a member** in the Agencies module, and the platform admin controller (`AdminAgencyMemberController`) only lists members and resets or exempts two-factor. The removal path I could find is the soft delete on `agency_users` (`deleted_at`) made outside the app routes I read. **Not read:** any console command or admin screen that sets `deleted_at` on a membership, and the user-deletion flow's effect on a member's memberships.
  So a removal leaves `owner_user_id` pointing at a user who is no longer an active member. The AH-119 owner column was created as `nullOnDelete` (migration `2026_11_06_100000`), which fires only when a user row is hard-deleted, and users are soft deleted. The plan therefore decides at read time: an owner who is no longer an active member is shown as "Former member" and counts as unassigned for the "Mine" filter.
- **ErasureRegistry.** The registry file is `apps/api/app/Modules/Erasure/Registry/ErasureRegistry.php`. Agency tables are decided `NotApplicable` with the reason "Agency-authored (§3.7)": `waves` is `NotApplicable`, `refs ['created_by_user_id']`, `classified ['name' => 'A wave title.']` (line 326). `agency_users` is `NotApplicable` ("a creator is never a row here"). `agency_creator_relations.owner_user_id` is decided `keep` (line 210: "an agency-side pointer, not creator data"). Erasure is a **creator** erasure: it does not remove agency members' rows, so an owner reference is not touched by it. A new table with a user reference needs a registry entry (`RegistryVerifier` checks every table and every FK to users or creators).

## 5. Existing patterns to reuse

| Need                         | What exists                                                                                                                                                                                                                 | Where                                                                                                 |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| Editable table               | Vuetify `v-table` for the waves panel; there is no inline-editable table in the modules I read.                                                                                                                             | `brands/components/BrandWavesPanel.vue`                                                               |
| Inline status change         | A switch whose handler re-reads the row and reverts on error (never a silently reverted switch), and the board's optimistic card move with revert.                                                                          | `campaigns/pages/CampaignListPage.vue` (around lines 300 to 410), `boards/components/BoardColumn.vue` |
| Date field                   | `v-text-field type="date"` (campaign form start and end dates; the new-wave go-live date).                                                                                                                                  | `campaigns/components/CampaignForm.vue` (lines 405, 412), `waves/components/NewWaveDialog.vue`        |
| Member picker                | No ready-made member picker. The owner reassign is an API wrapper only (`messageInbox.api.ts`, `PATCH …/creators/{creator}/owner`); the members list has an API wrapper. A `v-select` over `/members` is new UI.            | `messaging/api/messageInbox.api.ts`, `agency-users/api/members.api.ts`                                |
| Optimistic update and revert | The board's drag move (store re-home, revert on error).                                                                                                                                                                     | `boards` store and `BoardColumn.vue`                                                                  |
| Status pill                  | `AgencyStatusPill` with a kind and a tone table. A new kind means editing the frozen `agencyStatusTones.ts`; PL-1.1 and PL-1.2 did that and re-pinned the `agency-frozen` hashes in the same commit with the reason stated. | `agency/status/agencyStatusTones.ts`                                                                  |
| Progress segments            | `WaveProgressSegments.vue` (six fixed, empty).                                                                                                                                                                              | `waves/components/`                                                                                   |
| Idempotent first-use setup   | `BoardService::ensureBoard` (`firstOrCreate`, catches `UniqueConstraintViolationException`, provisions only when `wasRecentlyCreated`), run on the board GET.                                                               | `Boards/Services/BoardService.php` lines 43 to 68                                                     |
| Row lock                     | `lockForUpdate()` in a transaction (`AssignmentOfferEditor`, `MessageRevisionService`, `ErasureClaimer`). SQLite ignores it.                                                                                                | several                                                                                               |
| Owner must be a member       | `AgencyCreatorOwnerController`: active member check and 422 `owner.not_a_member`.                                                                                                                                           | `Agencies/Http/Controllers/`                                                                          |

## 6. Erasure, audit and tenancy touchpoints for a steps table

- **Erasure.** A new table (`wave_steps`) and a new column (`waves.steps_provisioned_at`) need decisions in `ErasureRegistry`; `RegistryVerifier` fails the Erasure folder for an undecided table or FK. The owner reference is a user reference: it takes `refs ['owner_user_id']` with `NotApplicable` (agency-authored, §3.7), the same shape as `waves.created_by_user_id`. A step name is free text typed by staff: classify it ("A step title, not creator data"), as `waves.name` is. In PL-1.2 removing a column decision did not turn the Erasure folder red for new columns (the verifier did not require them), so the registry entry for a column is not a proven gate; the table-level one is.
- **Audit.** New actions are needed (PL-1.1 added `WaveCreated` and `WaveUpdated`). Payload keys are written as literals so `AuditPayloadKeysGuardTest` reads them, and each key gets an Erasure decision (`AuditPayloadKeys::notPersonalKeys()`; PL-1.2 hit five undecided keys there). Owner is an id only, never a name (the AH-119 owner-change precedent).
- **Tenancy.** `BelongsToAgency` plus a tenancy scope on every agency table; the routes sit under `tenancy.agency` and `tenancy`; a wave of another agency is a 404 (`docs/security/tenancy.md` §3, referenced by the Waves routes). A new table needs `agency_id` and the trait; the step's wave must belong to the same agency as the request.

## 7. Walk baselines

- The agency walk (`apps/main/tools/walk/walk.mjs`, route table in `agency-routes.mjs`) lists `/brands`, `/brands/new`, `/brands/:ulid` (`brand-detail`), `/brands/:ulid/edit`, `/campaigns`, `/campaigns/new`, `/campaigns/:ulid`. **The wave page `/brands/:ulid/waves/:n` is not in that route table**, so it is not a baseline target.
- The targets that touch wave-related pages are the **brand** set (16 targets, `--only brand`) and the **campaign** set (12 targets, `--only campaign`, which includes the campaign page with the PL-1.2 wave card). Both were identical to `ah-115-agency-baseline/baseline.json` on 2026-11-18 at the end of PL-1.2 (COMPARE OK 12 and 16).
- With the flag OFF PL-1.3 must keep both identical: no change to `BrandWavesPanel` rendering while the flag is off, no new scoped style block and no false `v-if` on campaign or brand pages.

## 8. One check from PL-1.2: did `wave-dialog-menus.spec.ts` run in CI run 44305109051?

Report only, no change. `npx playwright test --list` was run from `apps/main` with the helper token set (`playwright.config.ts` itself requires `TEST_HELPERS_TOKEN`, so the list needs it).

- **Result:** `Total: 65 tests in 32 files`, and the list contains both of the spec's tests under the project `chromium`:
  - `[chromium] › wave-dialog-menus.spec.ts:30:3 › New wave dialog menus › a market picked inside the dialog becomes a chip, and the list stays inside the window`
  - `[chromium] › wave-dialog-menus.spec.ts:166:3 › New wave dialog menus, reopen sequence › a menu open when the dialog closes does not survive a quick reopen`
- **CI count matches:** the main SPA E2E job of run `44305109051` reported `65 passed (6.4m)`. The same 65 in the list and in the job is the evidence that the spec is in the set CI ran.
- **Config lines:** `apps/main/playwright.config.ts` line 62 `testDir: './playwright/specs'` includes it (the spec is in that folder). The only exclusion is `MOBILE_ONLY_SPECS = ['**/creator-shell-mobile.spec.ts']` (line 59), used as `testIgnore` for the desktop project (line 91) and `testMatch` for the mobile one (line 115). The wave spec matches neither pattern.
- **Limit:** the CI log does not print test names in the reporter output I read, so I did not see the two tests named in the log. The proof is the matching count (65 and 65) plus the list, not the log lines.

## 9. Not read

- Frame 01b: the right column, the tooltip and the wave tabs through `get_design_context`; all undrawn states; the collapsed groups opened; narrow widths.
- Frame 01: segment fills of each row (the colours and how many are filled for Wave 7, 8, 6 and 5), read only as names and sizes.
- `WaveDetailPage.spec.ts`, `BrandWavesPanel.vue` beyond the progress cell, the `agency-users` page, `members.api.ts` beyond its path.
- Any code that soft-deletes a membership outside the routes listed in §4, and the effect of the user-deletion flow on memberships.
- The agency's time zone: the `agencies` table has no timezone column in the migrations I searched; `users.timezone` exists (default `UTC`) and the app timezone is `APP_TIMEZONE` (default `UTC`). Not read: any agency-level setting.
- Production data (nothing was run against any deployed environment).
