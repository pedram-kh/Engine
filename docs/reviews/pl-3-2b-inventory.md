# PL-3.2b inventory: the rest of the script editor (lines, B-roll, market languages, reference images, reorder, the real estimate)

Status: **inventory only. No code, no migration, no push.** Read with `pl-3-2b-plan.md`. Branch `pl`, built on PL-3.2a (closed at code tip `01084935`, CI run `45712916684`, all four jobs green). Date 2026-11-26. Design source: Figma file `lYy90qzKk029vjoVCPExs2`. "Read" means read in the Figma file or in the code this session; "not read" is said where true. Earlier documents `pl-3-2-inventory.md` and `pl-3-2-plan.md` (2026-11-24) already describe the whole PL-3.2 design; this file does not repeat them. It re-reads the frames, records what PL-3.2a actually built, and lists where the two disagree.

## 0. Part A: the four loose ends of the PL-3.2a close

### A1. CI at the pushed tip

`ae67313d` (records only, pushed after the green run) has the long run id **`45717719464`** and ended **success**. Jobs: Backend (Pint + Larastan + Pest) success, Frontend (lint + typecheck + unit) success, E2E admin SPA (Playwright) success, E2E main SPA (Playwright) success. Nothing is red.

### A2. How `_test` routes are kept out of production (read, not changed)

| Layer             | What it does                                                                                                                                                                  | File and line                                                                                                                    |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Provider listed   | `TestHelpersServiceProvider::class` is the last entry of the provider list                                                                                                    | `apps/api/bootstrap/providers.php:53`                                                                                            |
| Registration gate | `boot()` returns before registering routes, the clock middleware, the queue-mode middleware and the rate-limiter neutraliser unless `gateOpen()`                              | `apps/api/app/TestHelpers/TestHelpersServiceProvider.php:53-60`                                                                  |
| The predicate     | `gateOpen()`: `app.env` must be exactly `local` or `testing` (anything else, including `staging` and `production`, is closed), **and** `test_helpers.token` must be non-empty | same file, `:76-85`                                                                                                              |
| Per-request gate  | every `/api/v1/_test/*` route sits in a group with `VerifyTestHelperToken`: a closed gate, a missing, wrong or empty token all give a **bare 404 with no body**               | `apps/api/app/TestHelpers/Routes/api.php:47` (group), `apps/api/app/TestHelpers/Http/Middleware/VerifyTestHelperToken.php:37-50` |
| This endpoint     | `agencies/setup` is inside that group                                                                                                                                         | `apps/api/app/TestHelpers/Routes/api.php:107-108`                                                                                |

Tests that prove the 404:

- `tests/Feature/TestHelpers/GatingTest.php:41-49` (the predicate is closed in `production` and `staging` whatever the token), `:59-79` (404 with no, wrong or empty token), **`:82-89` (404 when `app.env` is `production` even with the correct token, on `POST /_test/clock`)**, and the provider test at about `:112-136` (`boot()` with the gate closed registers no route).
- For **this** endpoint: `tests/Feature/TestHelpers/CreateAgencyWithAdminTest.php:89-95` proves 404 **without the token header** only.

**Plain answer: no test proves that `agencies/setup` answers 404 outside `local`/`testing` when the request carries a correct token and the new `agency_ulid` and `role` fields.** The group middleware makes it true by construction (the new fields are read after `VerifyTestHelperToken` has already refused), and `GatingTest:82` proves the same gate on another endpoint, but a test on this endpoint with the new parameters does not exist. **A small test is the first item of Stage 1** (`agencies/setup` with `agency_ulid` and `role=agency_staff`, `app.env=production`, correct token: 404 and no user and no membership created).

Two facts worth saying out loud:

- The gate names **`local` and `testing`**, not `e2e`. Playwright and CI start the API with `APP_ENV=local` (`apps/main/playwright.config.ts:153`, `.github/workflows/ci.yml:207` and `:395`). `apps/api/.env.example:2` is also `APP_ENV=local` and the example carries a token line (`:195-199`; value not printed here). So the only thing between a deployed server and the helpers is that **production sets `APP_ENV` to something else**. That is the documented contract (`app/TestHelpers/README.md`, "Gating contract") and the tests above prove the code side. **Not read: the production environment file or deploy configuration (not on this Mac).** It belongs on the "confirm before `pl` is merged" list as one line: "`APP_ENV` on staging and production is not `local` or `testing`".
- The endpoint can now create an agency-side **staff** user inside an existing agency (the Playwright staff test uses it). That is the same power the endpoint already had for a new agency's admin; it adds no new reach beyond a gate that is closed in production.

### A3. Creator walk baseline (146 targets): plan to make the creator flag-off check trustworthy again

Plan only. Nothing here is run in this prompt. Tech-debt row 59 says macOS 27.0 to 27.2.1 is suspected, **not proven**, and I now think it is the weaker hypothesis, for this reason: **the agency baseline (126 targets) recorded on the same macOS 27.0 still compares identical on 27.2.1.** If the OS change broke the tool, the agency set would be expected to break too. The creator run also shows **32 page errors that are the mock API answering 503**, which points at something creator-specific (a request the mock does not answer, or a screenshot-only difference on creator pages) as much as at the machine.

Steps, in order, each one cheap and each deciding something:

1. **Split the 638 differences by kind.** The compare reports DOM, style and theme-sheet hashes separately from the screenshot thumbnails. Count each kind. If only thumbnails differ: rendering (fonts, browser build) is the cause. If DOM or style hashes differ: the pages are really different (code or data), not the OS.
2. **List the 32 mock-API 503s by URL.** A URL the mock never answered means a request that was added after the baseline was recorded (a notification poll, a `/me` field, a feature check). If the list is short and explainable, the cause is the tool's mock, not the machine, and the fix is in the mock.
3. **Run the creator compare at the commit the baseline was recorded on** (a throwaway worktree, nothing else running, one run). Pass: the drift is code or mock coverage between that commit and now. Fail: the drift is the environment. This is the decisive test. Also run it twice on the same tree: two runs must be identical or the tool is not deterministic on this Mac and nothing it says can be trusted.
4. **Record versions on both sides:** `sw_vers`, the Playwright package version and its Chromium revision, the walk tool's own version hash, `pnpm-lock.yaml` hash. The baseline file should carry them (if it does not, that is the first improvement).
5. **Re-record the baseline from unchanged `main` (`09169b5a`)** in a worktree on the current Mac: record, then record again, and accept only if the two recordings are identical; store them in **their own commit** with the environment line in the message, **only after Pedram agrees** (a baseline is never changed silently).
6. **Compare `pl` to it with the flags off:** all 146 must be identical. A difference is investigated target by target, never "fixed" by re-recording. Re-run the agency 126 in the same sitting to show the tool still agrees with itself.
7. **Make it durable:** if step 3 says "environment", move the creator baseline to a pinned environment (the CI Playwright container image) so an OS update cannot invalidate it again, and say so in `WORKING-PROCESS.md`.
8. **If none of this works in half a day:** the creator flag-off proof is **Playwright creator specs plus Vitest plus Pedram's spot check**, and every report says "creator baseline not proven" until it is fixed.

When: **before sprint 3 closes** and, in practice, at the start of PL-3.2b Stage 1 (it needs no code and the walk stack is idle). It must not run at the same time as any other heavy process (local-run rule, one run alone).

### A4. Cleanup and the `catalyst_test` question

Docker Desktop was not running (the Mac had restarted), so I started it; the stack came back with the same containers (`catalyst-postgres` on 127.0.0.1:5435, plus redis, minio, mailhog).

Database printed before the drop: **`pl32_e2e`** (throwaway, created 2026-11-25 by me for the PL-3.2a Playwright runs). Dropped with `DROP DATABASE pl32_e2e WITH (FORCE)` through `docker exec catalyst-postgres psql`, connected to the maintenance database `postgres`. **What exists now on the server:**

| Database             | Size   | Created (approx., from the data directory) | What it is                                        |
| -------------------- | ------ | ------------------------------------------ | ------------------------------------------------- |
| `catalyst`           | 17 MB  | 2026-11-04                                 | Pedram's development database. **Never touched.** |
| `catalyst_e2e`       | 23 MB  | 2026-11-04                                 | the E2E database of earlier sprints               |
| `catalyst_rehearsal` | 13 MB  | 2026-11-21                                 | migration rehearsal (PL-2.4)                      |
| `catalyst_test`      | 57 MB  | 2026-11-06                                 | the Pest-on-Postgres database (below)             |
| `pl32_walk`          | 16 MB  | 2026-11-24                                 | the PL-3.2a walk database. **Left as it is.**     |
| `postgres`           | 7.5 MB | 2026-11-04                                 | the server's maintenance database                 |

**`catalyst_test`:** a PostgreSQL database inside the same Docker container as `catalyst`, but a **separate database** (separate data, nothing shared). Its name is the one `apps/api/phpunit.pgsql.xml` points at, and `docs/07-TESTING.md:182` tells a developer to `createdb catalyst_test` for local runs. It was created on **2026-11-06 at about 02:16 UTC**, the same day as commit `6c54fdf3` ("run the whole Pest suite on Postgres in CI (AH-119 A0)"), so by the session that built that CI leg on Pedram's machine; the server does not record who ran `createdb`, so I cannot name the author beyond "an earlier session on this Mac, before PL-3.2". `RefreshDatabase` wipes it on every run (`tests/TestCase.php` refuses to run unless the name ends in `_test` or `_e2e`), so it holds no data that matters. I used it for the PL-3.2a close-out Postgres runs. **From now on:** Postgres test runs for this chunk use a named throwaway **`pl32b_test`**, created at the start of Stage 1 and dropped at the end of the chunk, printed before every run; `catalyst_test` is left alone.

**The walk stack:** the API (8000), the main app (5173) and the admin app (5174) were stopped by the restart (nothing listens on those ports). I left them stopped, as asked ("leave pl32_walk and the walk stack as they are"); `pl32_walk` itself is intact and starts again on demand.

## 1. Design (B1)

### 1.0 What was read

All with `get_design_context` this session (session `8d651a3e-…`), file `lYy90qzKk029vjoVCPExs2`. The first call on `13:3108` returned structure only (the frame is too large for code), so the parts were read one by one.

| Node                            | What                                                                        | Read                                                                                |
| ------------------------------- | --------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| `13:3108`                       | 08 · Step 6 Script editor, 1600 x 3027 (structure, then the children below) | structure                                                                           |
| `13:3359`                       | script head (686 x 43)                                                      | code                                                                                |
| `13:3379`                       | workflow bar (686 x 52)                                                     | code                                                                                |
| `13:3405`                       | Brief (auto-filled), for the duration and the estimate line                 | code                                                                                |
| `13:3457`, `13:3555`, `13:3622` | Hook A (filled references), Hook B and Hook C (empty references)            | code                                                                                |
| `13:3689`                       | "Script lines" header with "Add line"                                       | code                                                                                |
| `13:3696`                       | Line 2, open                                                                | code                                                                                |
| `13:3803`                       | Line 3, open (with the shot-type dropdown open and a client comment)        | code                                                                                |
| `13:3916`, `13:3936`            | Line 4 collapsed, Line 5 collapsed with CTA                                 | code                                                                                |
| `13:4096`                       | 10 · Client share link, creator and script review                           | code, text only used                                                                |
| `133:3`                         | 12 · Creator app (the whole page of ten mobile screens)                     | structure; then `133:4` (12d Script overview) and `136:2` (12e Line detail) as code |

Frame `13:3108`, editor `13:3358` (686 wide), children in order (y, height): head `13:3359` (0, 43) · workflow `13:3379` (59, 52) · Brief `13:3405` (127, 199) · Hooks header `13:3450` (342, 36) · Hook A `13:3457` (394, 339) · Hook B `13:3555` (749, 293) · Hook C `13:3622` (1058, 274) · Script lines header `13:3689` (1348, 36) · Line 2 `13:3696` open (1400, 432) · Line 3 `13:3803` open (1848, 664) · Line 4 `13:3916` collapsed (2528, 48) · Line 5 `13:3936` collapsed (2592, 48). Notes panel `13:3952` is PL-3.3 and was not read again.

### 1.1 The script head and the estimate

| Element          | Frame values                                                                                                                                                                                                                                    | PL-3.2b                                                |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| Creator chip     | "SK1" 11 / 700 `#8b6cff`, border `rgba(139,108,255,.7)`, radius 4, padding 6 x 2                                                                                                                                                                | built (PL-3.2a)                                        |
| Title            | "Lucia Horváthová — Script 1" 20 / 600 `#ededed`                                                                                                                                                                                                | built                                                  |
| Last edited      | "Last edited by Jess · 20 min ago · v4" 12 `#6e6e76`                                                                                                                                                                                            | built in its own format; the version belongs to PL-3.3 |
| "Regenerate"     | accent button (fill `rgba(139,108,255,.12)`, text `#8b6cff` 13 / 500, 6 x 12, radius 8, spark icon 14)                                                                                                                                          | **not built: AI (PL-9)**                               |
| Language control | segmented control, border `#2e2e33`, radius 8, padding 3, gap 2; three items "EN", "SK", "Side by side"; item padding 10 x 4, radius 6, 12 / 400 `#a0a0a8`; selected item fill `#232326`, 12 / 600 `#ededed` ("Side by side" is drawn selected) | **built in 3.2b** (1.8)                                |
| Estimate         | not in the head: it sits in the Brief row "Duration": "~20 seconds" box, then "Estimated from lines: ~19s" 12 / 400 `#6e6e76`, right of it (`13:3449`)                                                                                          | built in 3.2a for hooks; 3.2b adds the lines           |
| Workflow bar     | Draft › Internal review › Client review › Approved, "Waiting on client", "Mark approved"                                                                                                                                                        | PL-3.3 (3.2a shows a single "Draft" pill)              |

The creator frame shows the same estimate as a pill "~20 sec" (`12d`, 11 / 500 `#a0a0a8`), next to the due date and the language chip, and a summary line "3 hooks" / "4 lines · 13 B-roll shots". **The sample script in the creator frame has 13 B-roll shots; see 1.15 (contradiction with the 12-per-script ruling).**

### 1.2 Hook fields (what lines and languages must sit next to)

Hook card `13:3457`: fill `#1b1b1d`, border `#2e2e33`, radius 10, padding 16, gap 12. Header: grip icon 14, "Hook A" 15 / 600 `#ededed`, dots menu 16. Rows have a **112 px label column** (icon 13, label 12 / 500 `#a0a0a8`, top padding 2) and gap 12:

- **Audio hook:** mic icon. Two columns inside the row, gap 10: the **EN** column (label "EN" 10 / 700 `#6e6e76`, text 13 / 400 `#ededed`) and the **SK** column (left border `#2e2e33`, left padding 10; label "SK" 10 / 700 `#6e6e76` and next to it **"Auto-translated · review"** 10 / 400 `#f5a524`; text 13 / 400 `#a0a0a8`).
- **Visual hook:** video icon; a shot-type chip (fill `#232326`, border `#2e2e33`, radius 6, padding 8 x 3, gap 5, video icon 12, label 12 / 500 `#ededed`, chevron 11) above the description 13 / 400 `#ededed`. **One column only**: no SK version.
- **Overlay:** type icon, label "Overlay" with "opt." 10 `#6e6e76`; EN and SK columns as the audio. Hooks B and C draw an empty overlay as one line "Add overlay (optional)" 12 `#6e6e76`.
- **Reference:** see 1.9.

Built in PL-3.2a: the EN column only, as a plain label "EN" (`ScriptHookCard.vue:223` and `:309`), text 13 / 19 `#ededed`, no input chrome (measured in the close-out). **"Auto-translated · review" is AI-only and is never built** (rule: by hand, PL-9).

### 1.3 Script lines (open)

Header row `13:3689`: "Script lines" 17 / 600 `#ededed`; button **"Add line"** 99 x 30 (border `#2e2e33`, radius 8, padding 12 x 6, plus icon 14, label 13 / 500 `#ededed`), at the right.

Open line card (`13:3696`, `13:3803`): same shell as a hook card (fill `#1b1b1d`, border `#2e2e33`, radius 10, padding 16, gap 12). Header: grip 14, **"Line 2"** 15 / 600, dots menu 16. Rows (112 px label column):

| Row       | Frame values                                                                                                                                                                              |
| --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Script    | mic icon, label "Script" 12 / 500 `#a0a0a8`; EN column (label "EN", text 13 / 400 `#ededed`) and SK column (left border, label "SK", "Auto-translated · review", text 13 / 400 `#a0a0a8`) |
| B-roll    | video icon, label "B-roll"; the rows of 1.6 and "Add shot"                                                                                                                                |
| Reference | image icon, label "Reference"; 1.9                                                                                                                                                        |

The line's line number runs from 2 (the hook is line 1): the frame shows "Line 2", "Line 3", "Line 4", "Line 5". **No character counter and no limit text is drawn anywhere on a line.**

### 1.4 Collapsed lines

Row `13:3916` (Line 4): fill `#1b1b1d`, border `#2e2e33`, radius 10, padding 16 x 14, one line, items gap 10: grip 14, "Line 4" 15 / 600 `#ededed`, the quoted text “And it lands before the meeting even starts.” 13 / 400 `#a0a0a8`, "· 4 shots" 12 / 400 `#6e6e76`; at the right an **image-count chip** (fill `#232326`, pill, padding 8 x 2, image icon 12, "1 image" 11 / 400 `#a0a0a8`) and a chevron-down 16.

Row `13:3936` (Line 5): the same shell; label **"Line 5 · CTA"**, then a **CTA chip** (fill `rgba(62,207,158,.15)`, padding 7 x 2, pill, "CTA" 10 / 500 `#3ecf9e`), the quote “Team lunch, sorted. Order it on Bolt Food.”, and **"· Visual: creator to camera"** 12 `#6e6e76` in the place where Line 4 has "· 4 shots"; chevron.

### 1.5 CTA marking

Drawn only on the collapsed Line 5: the "· CTA" in the label **and** the green chip (the label text and the chip both carry it). **No CTA control is drawn in either open line** (the toggle has no frame). The earlier inventory read "only the last line carries a CTA"; the ruling now allows **several** CTA lines, so the chip must appear on every flagged line, open or collapsed. **Not in the frame: where the toggle sits, whether the label repeats "· CTA".** Built from the design system and named (E16).

### 1.6 B-roll rows and codes

Row (`13:3734`...): fill `#0b0b0c`, radius 6, padding 8 x 6, gap 10; **code** 12 / 600 `#6e6e76`; **shot-type chip** (as the hook's: fill `#232326`, border `#2e2e33`, radius 6, 12 / 500 `#ededed`, video icon 12, chevron 11); **description** 13 / 400 `#ededed`. Rows are stacked with gap 6. **"Add shot"** below: plus icon 12, 12 / 400 `#6e6e76`, padding 8 x 4. There is **no grip and no remove control drawn on a B-roll row**.

Codes in the frame: Line 2 holds 1*1, 1_2, 1_3, 1_4 and Line 3 holds 2_1, 2_2, 2_3 (**line minus one**). The client frame and the creator frame use the same prefix (Line 3 holds 2_x). \*\*Ruling (2026-11-24, Q2, restated): `{line}*{n}` with the line number as shown, so Line 2 holds 2_1.\*\* FUNCTIONAL difference from every frame; the design side has to change the prefix.

The shot types (all frames agree; ten, the full menu, no scroll): **Wide shot · Medium shot · Close-up · Detail shot · POV · Over the shoulder · Top-down · Screen recording · Reaction shot · Creator to camera.** Dropdown `13:3862` (read earlier, unchanged): width 210, fill `#232326`, border `#2e2e33`, radius 8, shadow, padding 6; items 12 / 400 `#ededed`, padding 10 x 6, radius 5; the selected item fill `rgba(62,207,158,.1)`, text `#3ecf9e`, check icon. Stored codes already exist (`ShotType`, ten values, CHECK in `wave_script_hooks`).

### 1.7 B-roll in the later frames (so nothing built now blocks them)

- **Client frame** (`13:4096`): one text line per row, **"2_2 Screen recording — two restaurants, one order"** (code, shot-type name, an em dash and the description, 14 px), under the label "B-roll" 12 / 500 `#a0a0a8`. Lines are quoted in curly quotes, EN line 14 `#ededed` then the local line 14 `#a0a0a8`; the hook's visual is "Visual · Close-up" 12 / 500 then the description 14. Under a hook: "Reference" and the file names (11.5 `#6e6e76`) with the note "Click an image to view full size · comment on it like any line".
- **Creator frame** (`12d`, `12e`): the script is shown **in the creator's market language only** (chip "Slovenčina"), with "Original script" shown under an amended line. Every hook, line and **B-roll shot is its own upload slot** (counters "5/5", "2/4", "0/5"); a B-roll shot shows its **code** (2_1, 2_2, 2_3, 11 / 600 `#a0a0a8`), its shot type chip and **its description in the creator's language** ("Filtrovanie reštaurácií: vegánske, bezlepkové."). A reference image is a thumbnail with its file name and "Tap to view full size".
- **What this means for the build:** a B-roll row needs a **stable id** (its ulid) that PL-6 upload slots and PL-3.3 comments can point at; **the code is a display of position, never a key**, so it may renumber on reorder without breaking a slot. The creator frame also shows B-roll descriptions in the market language: see 1.15 (question Q3).

### 1.8 The language and side-by-side control

Frame: the segmented control of 1.1 in the script head (three items for one local language). The market bar (`13:3286`, 210 x 23, right-aligned, globe icon 14) reads "Script language: English + Slovak" 12 `#6e6e76`, **a plain label, no menu drawn**. The client frame's market bar reads "Showing English + Slovak". Per the rulings (Q4, 2026-11-24): the languages are **set in the workspace** by admin, manager and staff (the PL-2.4 market builder is untouched). **Not drawn:** the control with only English; the control with more than one local language; the language menu; the removal confirm; a text field of a language that was removed. All built from the design system and named (E16).

### 1.9 Reference images

| Element                         | Frame values                                                                                                                                                                                                                                                 |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Row                             | image icon 13, label "Reference" 12 / 500 `#a0a0a8`; on hooks **and on lines** (both have the same field)                                                                                                                                                    |
| Thumbnail                       | 104 x 68, border `#3a3a40`, radius 6, a placeholder icon 20; a **remove** button top right (fill `rgba(11,11,12,.8)`, padding 3, pill, x icon 10); the file name below, 11 / 400 `#6e6e76`, gap 4                                                            |
| Add tile (when images exist)    | 68 x 68, border `#3a3a40` (dashed in the earlier read), radius 6, plus icon 16, "Add" 11 / 500 `#3ecf9e`                                                                                                                                                     |
| Empty (Hooks B and C, Line 3)   | a dropzone: height 58, "Upload reference image" 13 / 500 `#3ecf9e`, helper 11.5 / 400 `#6e6e76` (earlier read: "Drag & drop or browse · JPG, PNG, GIF up to 10MB · multiple allowed"; fill `rgba(35,35,38,.4)`, dashed `#3a3a40`, radius 8, padding 14 x 12) |
| Collapsed line                  | the image-count chip (1.4)                                                                                                                                                                                                                                   |
| Viewer, progress, errors, limit | **not drawn** (the client and creator frames say only "view full size")                                                                                                                                                                                      |

**FUNCTIONAL differences from the helper text:** it says "up to 10MB"; the ruling is JPG, PNG, WebP and GIF, 10 per block and **300 MB in total per script**, with no per-image cap. The helper text becomes a string of the plan.

### 1.10 Reorder handles

Grip icon (14) at the left of **every hook header and every line header** (collapsed or open). **No handle on a B-roll row, none on a thumbnail.** No drag preview, no drop indicator, no "Move earlier / later" menu are drawn. The block menu (dots, 16) has no drawn items (built in 3.2a: Duplicate, Delete; PL-3.2a plan: Move earlier / later). Reorder in 3.2b: hooks, lines and the images inside a block (drag and keyboard); **B-roll rows have no handle in the frame and are not reordered** (default; Q-list "defaults" in the plan).

### 1.11 Counters and limits

**Nothing is drawn**: no "n/30", no "n/12", no "3/10 images", no byte total. The ruling says limits are shown **only near the limit**, not as targets. The only counters in the frames belong to later chunks (creator upload counters "5/5", the rail "Add script (n/3)", which is built). So every limit message is a string of the plan, built from the design system.

### 1.12 Empty states

Drawn: an empty reference row (the dropzone); an empty overlay ("Add overlay (optional)"). **Not drawn:** a script with no lines, a line with no text, a line with no B-roll, a market with no second language, a script with no estimate yet. The creator frame's "Script lines" subtitle "Each line = your take to camera + its B-roll shots" is creator copy, not an editor empty state.

### 1.13 The estimate

Brief row, `13:3441`-`13:3449`: label "Duration" 12 `#6e6e76`, a select-like box "~20 seconds" (the choice 15, 20, 30, 45 or 60), then "Estimated from lines: ~19s" 12 / 400 `#6e6e76`. Built in 3.2a as "Estimated length: about N s" from the hooks; the server computes it (`ScriptEstimate::of`, 2.5 words per second, longest hook plus the lines, rounded up). **Frame text says "Estimated from lines: ~19s"**: a string difference to record (the built wording already differs and Pedram walked it).

### 1.14 Which parts have no frame

No frame at all: the language menu and its confirm; the CTA toggle; drag and drop states and the keyboard path; the image viewer, upload progress, upload errors and the limit states; every limit message; the empty script, empty line and empty B-roll states; the line delete confirmation and the line block menu items; every conflict and saving state (built already for hooks); the narrow-screen editor; a market with only English. All are built from the design system and named in the plan (E16).

### 1.15 Differences and contradictions (frame against rulings, and rulings against each other)

| #   | What                                                                                                                                                                                                             | Class                                                                                          |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| D1  | B-roll code prefix: frames use line minus one; ruling `{line}_{n}`                                                                                                                                               | FUNCTIONAL, ruled (Q2); design needs a change                                                  |
| D2  | "Auto-translated · review" pills and "Regenerate"                                                                                                                                                                | AI-only, not built (PL-9)                                                                      |
| D3  | "Last edited by Jess · … · v4"                                                                                                                                                                                   | v4 is PL-3.3                                                                                   |
| D4  | Helper text "JPG, PNG, GIF up to 10MB" against 4 types, 300 MB per script                                                                                                                                        | FUNCTIONAL, ruled                                                                              |
| D5  | Frame shows the language control for one local language                                                                                                                                                          | the plan extends it, Q4 below                                                                  |
| D6  | **The creator frame's sample script has "4 lines · 13 B-roll shots"; the ruling is up to 12 B-roll rows per script.** The frame's own example would not fit. The earlier PL-3.2 plan said 12 **per line** (1.11) | **real contradiction, question Q1**                                                            |
| D7  | The creator frame shows B-roll descriptions in the creator's language; the editor frame draws one column for B-roll and the earlier ruling (E8) says visual descriptions and B-roll are English only             | **real contradiction for PL-6, question Q3**                                                   |
| D8  | The collapsed CTA line shows "· Visual: creator to camera" where Line 4 shows "· 4 shots"; no open line draws a visual field                                                                                     | unclear data source; built as the shot count ("· 2 shots", "· No shots") and listed for Pedram |
| D9  | The earlier plan gave **each script one local language** (`wave_scripts.language`); the new ruling shows **all of the market's languages side by side**                                                          | the plan follows the new ruling; the unused column is dealt with in the plan (C1)              |
| D10 | Earlier inventory: "only the last line carries a CTA"; ruling: several                                                                                                                                           | superseded by the ruling                                                                       |

## 2. The developer brief (B2)

**Not read.** "Strategy & Scripts: Developer Brief v2" (Jordan, 1 Oct 2026, Word) is not in the repository, on this Mac in any place I was allowed to look, or in the Figma file; `new-sections-design-review.md:9` and `pl-2-1-inventory.md:147` already record that only Pedram's relay (S1 to S10 and the E1 to E16 rulings of 2026-11-24) has ever been available. So I cannot quote the brief. What the repository records the brief as saying, **all second hand**:

- **Lines:** lines start at 2 (the hook is line 1) with text per language, B-roll rows and a CTA flag (E6, `pl-3-2-plan.md:9-25`).
- **B-roll codes:** `{line}_{n}`, "Line 2 would hold 2_1" (`pl-3-2-inventory.md:118`, "the brief (E6) says").
- **Shot types:** "a fixed list" (`pl-3-2-inventory.md:141`, "the brief says fixed").
- **Languages:** per market and per script, the toggle, where set, removal (E8).
- **Reference images:** four types, 300 MB per script in total, GIF, a per-block count, reorder (E9).
- **CTA:** a flag on a line (E6); several lines allowed by Pedram's ruling Q3.

**Conflicts with the rulings in this prompt:** none found in what the repository records. The two frame-against-ruling conflicts (D6, D7) come from the Figma frames, not from the brief. **If the Word document can be put in the repository (or pasted), B2 should be redone from the text before Stage 1 starts; it is cheap and it is the one source I could not read.**

## 3. What exists (B3)

### 3.1 PL-3.2a schema (migrations `2026_11_24_100002` to `100004`, plus `100000` and `100001`)

| Table                    | Holds                            | Notes for 3.2b                                                                                                                                                                                                                                                                                                                                                            |
| ------------------------ | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `wave_scripts`           | one script of one creator's slot | `number` 1..3 CHECK; **partial unique index `(wave_id, creator_id, number) WHERE archived_at IS NULL`**; `archived_at` and `wave_creator_id` CHECKed together; `angle`, `duration_seconds` (15/20/30/45/60); **`language char(2) nullable` exists and nothing writes it**; `revision` (every change) and `brief_revision` (brief only); parent key `unique (wave_id, id)` |
| `wave_script_hooks`      | hooks in order                   | `position`, `shot_type` (nullable, CHECK = the ten codes), `visual_description`, `revision`; parent key `unique (script_id, id)`                                                                                                                                                                                                                                          |
| `wave_script_hook_texts` | audio and overlay per language   | `field` CHECK (`audio`, `overlay`), `language char(2)`, `body` (CHECK non-empty after trim), `origin` CHECK = `manual`; unique `(hook_id, field, language)`. **English is the row `language='en'`; a market language is a second row. No schema change is needed to hold a second language for hooks.**                                                                   |

All composite FKs carry `(wave_id, …)` so a child cannot name another wave's parent. No image table, no line table, no B-roll table, no language table yet.

### 3.2 Services, endpoints, presenter

- `ScriptGuard::transaction(Wave, Closure)`: wave row lock first, then `lockedScript` (script row `FOR UPDATE`, archived and slot-less scripts are a 404). A source-string test (`ScriptsSourceTest` G14, allowed writers `ScriptService`, `ScriptHookService`, `ScriptArchive`, `StrategyWaveMarketGuard`) fails if any other file writes script tables or opens a bare `DB::transaction(`. **New writers must be added to that list on purpose.**
- `ScriptHookService::update` (`app/Modules/Strategy/Services/ScriptHookService.php:56-125`): compares what really changes; **no real change writes nothing**; a stale `revision` with a real change throws `scriptConflict('hook', current)` (409); it **refuses any language other than `en`** (`scriptLanguageNotAvailable`), which 3.2b relaxes to the market's languages; every save bumps the hook `revision` and the script `revision`; audit row with literal keys.
- `ScriptArchive` (`Services/ScriptArchive.php`): `archive`/`archiveOne`, `restore`/`restoreOne` (keeps the number when free), `deleteLive`/`deleteOne` (deletes the script's hook texts and hooks, then the script, with one audit row), `liveNumbers`/`numberFor`. **Every new child table must be added to `deleteOne` (children first) and be carried along by archive and restore** (archive and restore only move `archived_at`/`wave_creator_id`, so children follow without code; delete does not).
- `StrategyWaveMarketGuard::purgeScripts` deletes the scripts of removed markets through `ScriptArchive::deleteOne`.
- Routes (`Routes/api.php:55-67`): `scripts`, `scripts/archived`, `…/archived/{script}/restore`, `DELETE …/archived/{script}`, `scripts/{script}`, `…/brief`, `…/archive`, hooks `store`, `update`, `duplicate`, `destroy`. Abilities from `WaveStrategyPolicy` (`manageScripts` for all three agency roles; `deleteScriptsInUse` admin and manager). Three flags are all required, 404 otherwise.
- `ScriptPresenter::script` loads hooks and texts and returns `estimate` = `ScriptEstimate::of($audio)`; **`ScriptEstimate::of($hookAudio, $lineText = [])` already takes the lines** (its docblock says "none until PL-3.2b"), so the estimate rule needs no new function, only the lines passed in.
- Limits in `StrategyLimits`: hooks per script 6, on create 3, angle 300, hook audio 300, overlay 100, visual 400, durations. Lines, B-roll and image limits are **not defined yet** (the image ones exist for concepts: `IMAGES_PER_CONCEPT`, `IMAGE_TOTAL_BYTES = 300 MB`, `IMAGE_FILENAME = 255`).

### 3.3 Web

`components/ScriptsStep.vue` (page), `ScriptsRail.vue`, `ScriptEditor.vue` (head, brief, hooks, archive, `provideSaveRegistry`), `ScriptBrief.vue`, `ScriptHookCard.vue` (546 lines, one `useBlockAutosave` per hook), `ScriptsLeaveDialog.vue`, `ArchivedScriptsDialog.vue`; composables `useBlockAutosave.ts` (debounce 1.5 s, retries 3 s and 10 s, a 409 keeps the person's text and holds theirs beside it; **`keepMine` uses the revision from the conflict once**), `useSaveRegistry.ts` (the leave guard registry), `useAuthenticatedImage.ts`; logic `scriptsLogic.ts`; the shared `AutoGrowTextarea.vue`. API client `strategy.api.ts` and types `scripts.ts` in `packages/api-client`.

### 3.4 The concept image pipeline: exactly what can be reused as is

| Piece                                                                                                                                                                            | File                                                                                                                                                       | Reusable as is for scripts?                                                                                                                                                              |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Storage disk, `copy`, `head` (first 64 KB), `deleteAll(keys)`                                                                                                                    | `Services/ConceptImageStorage.php` (static, string keys)                                                                                                   | **Yes**, called not edited                                                                                                                                                               |
| Allowed types                                                                                                                                                                    | `ConceptImageStorage::MIME_TYPES` = JPEG, PNG, WebP                                                                                                        | **No**: GIF is missing; `sniff()` returns null for GIF. Scripts need their own list and sniff (the same `getimagesizefromstring`, one more case, plus the `GIF87a`/`GIF89a` start check) |
| Key scheme                                                                                                                                                                       | `conceptPrefix`, `imageKey`, `thumbKey`                                                                                                                    | **No**: concept-shaped; a script prefix is a sibling function                                                                                                                            |
| `init` (reservation under a row lock, count and total check, presign), `complete` (verify stored object, mark ready, dispatch thumbnail), `delete` (objects first, then the row) | `Services/ConceptImageService.php`                                                                                                                         | **No**: tied to `ConceptService::lockedConcept`, `WaveConceptImage` and the concept tracker sync. The **logic and ordering are the model**; the class is copied as a sibling             |
| Count and total limits                                                                                                                                                           | `IMAGES_PER_CONCEPT` (10), `IMAGE_TOTAL_BYTES` (300 MB), `IMAGE_FILENAME` (255), reservation minutes                                                       | numbers match the rulings; new constants for scripts                                                                                                                                     |
| Thumbnails                                                                                                                                                                       | `ConceptThumbnailer` (512-edge JPEG, GD, skip above 32 MiB, 40 MP, 30 000 px) and job `GenerateConceptImageThumbnail`                                      | **No**: they take a `WaveConceptImage`. Same limits and approach for a `WaveScriptImage`; first frame only for GIF                                                                       |
| Sweeper                                                                                                                                                                          | `Console/SweepPendingConceptImages` (`strategy:sweep-pending-concept-images`)                                                                              | **No**: a sibling command; **its scheduler entry in production is still on the "must confirm before `pl` is merged" list** and the new one joins it                                      |
| Serving                                                                                                                                                                          | authenticated stream, `nosniff`, no presigned GET                                                                                                          | pattern reused                                                                                                                                                                           |
| Web                                                                                                                                                                              | `useConceptImageUpload.ts` (3 parallel, per-file `init`, retry that resumes), `ConceptImageTile.vue`, `ConceptImageViewer.vue`, `useAuthenticatedImage.ts` | the composable and tile are **concept-shaped** (scope, `conceptId`, `conceptsLogic.planUploads`); `useAuthenticatedImage` is reusable as is; the rest is copied and adapted              |

PL-3.2b therefore **duplicates the engine as siblings** and leaves PL-2.3 untouched (the earlier plan said so: "No PL-2.3 file changes"); the duplication is a tech-debt row. Row **40** applies unchanged: no virus scan or content check beyond the header sniff; **must be solved before PL-4** (clients upload). Script images are uploaded by agency staff only, so the 3.2b exposure is the same class as concept images.

## 4. Where market languages would live (B4)

- `wave_markets` (PL-2.4): `ulid`, `name`, `code` (2 to 6 characters), `position`; countries in `wave_market_countries`; a market is a **named group of countries**, so the Nordics market (SE, NO, DK, FI) naturally wants up to four languages. There is **no language column or table** on markets.
- The workspace: `MarketTabs.vue` (the market bar with tabs and counts). **The "Script language: English + Slovak" label of the frame was not built in PL-3.2a** (no match for it in the strategy web module); the rail card shows the creator's own language by `languageEndonym`.
- Language codes: `app/Core/Enums/Locale.php` (`WORLD_LANGUAGES`, ISO 639-1, mirrored in `packages/api-client/src/locales.ts` with a parity test); creators have `primary_language` and `secondary_languages`.
- **What "language" means today in a hook text row:** `wave_script_hook_texts.language` is a two-letter code; the built editor writes only `en` and labels the field "EN" as plain text. `wave_scripts.language` exists, is null everywhere and is read by nothing.
- Plan consequence (C4): market languages become **data of the market** (`wave_market_script_languages`, English implicit and not stored); the script has no language of its own; the editor shows English plus every language of its market.

## 5. Audit, Erasure and Tenancy (B5)

- **Audit cases today** (`AuditAction.php:605-613`): `wave_script.created|updated|deleted|archived|restored`, `wave_script_hook.created|updated|duplicated|deleted`. Metadata is a **literal array** (a static test, `AuditPayloadKeysGuardTest`, reads the keys) of ids, the script number, field names and counts; `AuditActionEnumTest` holds the expected list; a new key needs `AuditPayloadKeys`. **Script text, B-roll descriptions and image file names are free text and never go into an audit row.**
- **3.2b adds** (names fixed in the plan): `wave_script_line.created|updated|duplicated|deleted|reordered`, `wave_script_broll.created|updated|deleted`, `wave_script_hook.reordered`, `wave_script_image.added|removed|reordered`, `wave_market_script_languages.updated`.
- **Erasure registry** (`ErasureRegistry.php:393-401`): `wave_scripts`, `wave_script_hooks`, `wave_script_hook_texts` are `NotApplicable` (agency-authored, §3.7) with their free-text columns `classified` (tech-debt 48). `ErasureRegistrySchemaTest` fails on **any unclassified new table, column or user/creator foreign key**, so every new table needs a rule in the same commit. Precedent for images: `wave_concept_images` is `NotApplicable`, `refs ['uploaded_by_user_id']`, the files are **not** erased. `WaveScriptErasureTest` (G15) proves an erased creator's scripts, hooks and texts are untouched and the creator row is anonymised in place (the rail reads "Deleted creator").
- **Tenancy:** `BelongsToAgency`, `agency_id` from the locked wave, never from the request; composite FKs `(wave_id, …)`; routes under `['auth:web','tenancy.agency','tenancy']`; tests `StrategyAccessTest` (T1), `ScriptsApiTest` G2 (another agency, brand, wave or another wave's script id is 404), the `Tenancy` folder.

## 6. Not read, or not run

- The developer brief (Word). The creator frame's other screens (12a to 12c, 12f to 12j; only 12d and 12e were read) and the rest of `13:4096` beyond its text. The production environment file and deploy configuration (A2). `WORKING-PROCESS.md` beyond §4.1's title. The 3.2a walk's measured numbers were used from the close-out, not re-measured.
- Nothing was run: no test, no migration, no database change except dropping `pl32_e2e` and starting Docker Desktop (A4).
