# PL-2.3 review record (Stage 1 backend accepted, Stage 2 web)

## Stage 1 review: accepted differences (recorded as ruled, 2026-11-21)

Pedram reviewed Stage 1 and accepted these differences from the plan:

1. The status columns are `varchar(20)`.
2. The outright persona refusal (ruling Q4).
3. `complete` keeps the reservation when the stored object is missing.
4. There is no reorder endpoint for concepts.
5. Two Console tests were made tolerant of the cron column width.

## A1: which actions send an Approved concept back to "Awaiting client"

Only a concept whose status is **Approved** is asked about. The web asks first; the server does the reset in the same request.

| Action on an Approved concept                                       | Asks first? | Result                                          |
| ------------------------------------------------------------------- | ----------- | ----------------------------------------------- |
| Edit a field and save (persona, funnel stage, note, USPs, any text) | Yes         | Awaiting client, approval cleared               |
| Add an image                                                        | Yes         | Awaiting client                                 |
| Remove an image                                                     | Yes         | Awaiting client                                 |
| Add a link                                                          | Yes         | Awaiting client                                 |
| Edit a link                                                         | Yes         | Awaiting client                                 |
| Remove a link                                                       | Yes         | Awaiting client                                 |
| Save an edit that changes nothing                                   | No          | Nothing is written, nothing is asked            |
| Record a decision, Duplicate, Delete                                | No          | Not edits (Duplicate makes a new Awaiting copy) |

Rules around it:

- The confirmation says the client's approval will be cleared. **Cancel changes nothing**: the edit dialog stays open with the typed text, no request is sent, the status stays Approved. For an upload, Cancel means no bytes are sent.
- It is asked **before the first such change** only: once the concept is Awaiting client it is not asked again.
- A concept in **Changes requested** or **Awaiting client** is never asked, and an edit does not change a Changes requested concept's red state or its comment.
- Proof: break-reverts W10 to W13d and W17 (`pl-2-3-break-reverts.md`), and the real-browser walk below (Cancel kept Approved, OK gave Awaiting client, the second edit did not ask).

## Stage 2 (web)

### Method

Frame 13:4412 (file `lYy90qzKk029vjoVCPExs2`, 1600 x 2264, "05 · Step 4 – Concepts (awaiting client approval)") was read with `get_design_context` after loading `/figma-design-to-code`. The whole frame comes back as geometry only; the card (13:4632) and the approval gate (13:4615) came back as full style code and are the source of the values below. The built page was opened in Chromium at 1600 wide (frame width) and at 1440, 768 and 390, with the `Pl23WalkSeeder` data on a throwaway database (`pl23_walk`, PostgreSQL port 5435, API 8003, Vite 5185). Computed styles were read back with `/tmp/pl23/measure.mjs`.

Frame colours map to theme tokens. **Token note:** the agency theme lifts `text-tertiary` from the frame's `#6e6e76` to `#8a8a92`, so every tertiary text reads (138, 138, 146). The frame's Material-style icons have no asset in the project; the nearest Material Design Icons glyphs are used at the frame's sizes (`mdi-tray-arrow-up` for the frame's upload icon, `mdi-link-variant`, `mdi-image-outline`, `mdi-close`, `mdi-dots-horizontal`).

Screenshots, outside the repo:

- `/tmp/pl23/walk/frame-13-4412.png` (the frame)
- `/tmp/pl23/walk/built-1600-full.png` (built, 1600 wide, full page)
- `/tmp/pl23/walk/side-by-side-frame-left-built-right.png` (both, same scale)
- `/tmp/pl23/walk/built-card-changes.png`, `built-banner.png`, `built-tile.png`, `card-uploaded.png`, `viewer.png`, `reset-dialog.png`, `decision-dialog.png`, `dialog-add-1440.png`, `dialog-add-filled.png`, `upload-limit-notice.png`, `link-refused.png`, `menu-open.png`, `narrow-768.png`, `narrow-390-top.png`, `narrow-390-dialog.png`

### Fidelity table

| Element                                  | Frame                                                                                        | Built                                                                                                                                | Class                | Note                                                                                                                         |
| ---------------------------------------- | -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | -------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| Card                                     | `#1b1b1d`, 1px `#2e2e33`, radius 12                                                          | (27,27,29), 1px (46,46,51), radius 12                                                                                                | matches              | Changes requested card border: red at 50% (frame's third card)                                                               |
| Card head                                | fill purple 6%, pad 16/18/14, gap 10                                                         | purple 6%, pad 16/18/14, gap 10                                                                                                      | matches              |                                                                                                                              |
| "CONCEPT 1" chip                         | `#0b0b0c`, pad 2/8, radius 6, 10.5 / 700, tracking 0.6                                       | (11,11,12), pad 2/8, radius 6, 10.5 / 700, 0.6px                                                                                     | matches              |                                                                                                                              |
| Status pill                              | green 15%, pad 3/9, radius 999, dot 6, 11 / 500, text `#34c77b`                              | green 15%, pad 3/9, 999, dot 6, 11 / 500, (52,199,123)                                                                               | matches              | Awaiting amber `#f5a524`, Changes requested red `#f0506e`, same shape                                                        |
| Pill text                                | "Approved by client"                                                                         | "Approved"                                                                                                                           | VISUAL, ruled        | Ruled (2026-11-21): the pill stays "Approved"; the footer carries "Approved by {name} · {date}"                              |
| Title                                    | 18 / 600, `#ededed`                                                                          | 18 / 600, (237,237,237)                                                                                                              | matches              | The frame draws curly quotes inside the sample text; the built title is the stored text                                      |
| Persona pill                             | border purple 60%, pad 3/9, code 10 / 700 purple, title 12 / 500                             | purple 60%, pad 3/9, 10 / 700 (139,108,255), 12 / 500                                                                                | matches              |                                                                                                                              |
| Funnel chip                              | `#232326`, pad 3/9, 12 / 400 `#a0a0a8`, chevron 11, gap 4                                    | (35,35,38), pad 3/9, 12 / 400 (160,160,168), gap 4                                                                                   | matches              | Opens a menu of the five stages (FUNCTIONAL addition: the frame's chevron is the cue)                                        |
| USP chips                                | tinted 14%, dot 6, 12 / 500, pad 3/9, gap 5                                                  | tinted 14%, dot 6, 12 / 500, pad 3/9, gap 5                                                                                          | matches              |                                                                                                                              |
| Body                                     | pad 16/18, gap 12                                                                            | pad 16/18, gap 12                                                                                                                    | matches              |                                                                                                                              |
| Row label                                | 108 wide, 12 / 500, `#6e6e76`                                                                | 108 wide, 12 / 500, (138,138,146)                                                                                                    | matches (token note) |                                                                                                                              |
| Row value                                | 13 / 400, line 19, `#ededed`                                                                 | 13 / 400, 19, (237,237,237)                                                                                                          | matches              |                                                                                                                              |
| Hook, Payoff, CTA boxes                  | yellow 6% fill, border yellow 70%, pad 7/10, radius 6, italic 13 / 19                        | yellow 6%, yellow 70%, pad 7/10, radius 6, italic 13 / 19                                                                            | matches              | The 70% border is `--agency-matrix-line-concept` in `matrixColors.css` (a literal is allowed only there)                     |
| Key points                               | bullets `#6e6e76`, gap 6, rows gap 3, 13 / 19                                                | bullets (138,138,146), rows gap 3, 13 / 19                                                                                           | matches (token note) |                                                                                                                              |
| Approved footer                          | none drawn on the frame's card                                                               | green 7% fill, 30% top line, pad 12/18, avatar 24, "Approved by {name} · {date}"                                                     | FUNCTIONAL           | Item 3. Not in the frame; the date uses `Intl.DateTimeFormat` in the user's language                                         |
| Changes requested footer                 | not drawn                                                                                    | red 7% fill, 30% line, avatar 24, name and date 11.5, comment 13 / 19                                                                | FUNCTIONAL           | Item 3                                                                                                                       |
| References block                         | top line 1px `#2e2e33`, pad 14/18/16, gap 10                                                 | 1px (46,46,51), pad 14/18/16, gap 10                                                                                                 | matches              |                                                                                                                              |
| References title row                     | icon 14, "References" 13 / 600, "images & links" 12 / 400                                    | icon 14, 13 / 600, 12 / 400                                                                                                          | matches              |                                                                                                                              |
| Image / Link buttons                     | 1px `#2e2e33`, pad 4/9, radius 6, icon 12, text 12 / 500 `#3ecf9e`, gap 6                    | 1px (46,46,51), pad 4/9, radius 6, icon 12, 12 / 500 primary                                                                         | matches              |                                                                                                                              |
| Image tile                               | 96 x 64, radius 6, gradient, remove 10px in a dark circle at left 74 top 5, caption 10.5     | 96 x 64, radius 6, thumbnail or placeholder, remove 16px circle (top 5, right 6), caption 10.5                                       | matches              | Frame's gradient is placeholder art; real thumbnails replace it, the placeholder is a flat tint (icon 18 at left 39, top 23) |
| Link row                                 | `#232326`, pad 8/10, radius 8, icon 13, title 13 / 500, domain 11.5 `#6e6e76`, close 13      | `#232326`, pad 8/10, radius 8, icon 13, 13 / 500, 11.5, remove                                                                       | matches              | Added: an edit pencil per link (FUNCTIONAL)                                                                                  |
| Drop line                                | not drawn                                                                                    | dashed, shown only with no image, link or upload; click opens the file picker                                                        | FUNCTIONAL           | Drag and drop was in the plan (C4); one line, gone once the first reference exists                                           |
| Approval banner                          | amber 8% fill, border amber 40%, pad 14/16, radius 10, text 14 / 600 amber, sub 12 `#ededed` | amber 8%, amber 40%, pad 14/16, radius 10, 14 / 600 (245,165,36)                                                                     | matches              | Turns green when all concepts are approved; text is the counts, no lock icon text                                            |
| Banner buttons                           | "Send reminder", "Approve on client's behalf" (13 / 500, pad 6/12, radius 8)                 | "Record client's decision" (admin, manager)                                                                                          | FUNCTIONAL           | Send reminder has no backend (no client portal before PL-4); the second is the ruled "record the decision" button            |
| "Generate concepts" button               | drawn beside Add concept                                                                     | absent                                                                                                                               | FUNCTIONAL           | Generation is a later sprint                                                                                                 |
| "Preview as client", "Share with client" | drawn in the page header                                                                     | absent                                                                                                                               | FUNCTIONAL           | Client portal is PL-4                                                                                                        |
| Status chip in the page header           | "Concepts in client review"                                                                  | absent                                                                                                                               | FUNCTIONAL           | The banner carries the state                                                                                                 |
| Stepper, step 4                          | "Client review · 1 of 3"                                                                     | "1 of 3 approved"                                                                                                                    | FUNCTIONAL           | Item 1: the plan's wording                                                                                                   |
| Stepper, steps 5 and 6                   | lock icon, "Unlocks after concepts"                                                          | no lock, "Not available yet"; the warning with counts when concepts are not all approved                                             | FUNCTIONAL           | Never locked: Creators and Scripts are built in PL-3, so steps 5 and 6 cannot be opened (not because of concepts). D1 below  |
| Comment count with icon on the head      | message icon 13 and "1" in amber, dots menu 16                                               | dots menu only                                                                                                                       | FUNCTIONAL           | Per-concept comments are not part of PL-2.3; the comment is the client's one decision comment, shown in the footer           |
| "Notes & comments" column                | 300 wide, right of the grid                                                                  | absent                                                                                                                               | FUNCTIONAL           | As on the USP and persona steps                                                                                              |
| Card width                               | 444 (two cards per row beside the 300 column)                                                | fluid: 620 at 1600 wide (two per row)                                                                                                | VISUAL, ruled        | Ruled (2026-11-21): stays fluid until the notes column exists (PL-2.4)                                                       |
| Decision dialog: choice                  | not drawn                                                                                    | two buttons in a radio group; selected = filled (green or red) with a check, other = outlined with an empty circle; `aria-checked`   | FUNCTIONAL           | PL-2.3 walk P1; the design system's selected state; wraps at 390 (no clipping)                                               |
| Decision dialog: why Save is off         | not drawn                                                                                    | one line in the error colour with an icon, as the matrix hint                                                                        | FUNCTIONAL           | PL-2.3 walk P1                                                                                                               |
| Persona refusal dialog                   | not in the frame                                                                             | title "{name} can't be deleted"; groups "Concepts" and "Matrix cells"; link "See this persona's concepts"; count and sentence kept   | FUNCTIONAL           | PL-2.3 walk P2                                                                                                               |
| Concept dialog: angle suggestions        | not drawn                                                                                    | lightbulb button inside the Angle field opens a list: USP, origin (manual or synced), the line                                       | FUNCTIONAL           | PL-2.3 walk A1; no frame, named as built                                                                                     |
| Image viewer: Download                   | not drawn                                                                                    | text button with a download icon, left of the close button                                                                           | FUNCTIONAL           | PL-2.3 walk A2                                                                                                               |
| Add concept tile                         | dashed, centred, plus icon, "Add concept", caption                                           | dashed 1px (46,46,51), pad 24, gap 10, 160 high; plus in a 46px circle at 12% primary; title 15 / 600 (62,207,158); caption 12 / 400 | matches              |                                                                                                                              |
| Market bar                               | "MARKET" label, tabs with code chip, "Add market"; "Script language" on the right            | tabs with code chip and count; no "Add market"; no script-language line                                                              | FUNCTIONAL           | As on the matrix step (Add market and Script language were ruled out in PL-2.1)                                              |

**VISUAL differences found in this pass and fixed:** the reference tile (size, caption, remove button position), the references block layout (title row, button shape, link rows), the banner (padding, font, border at 40%), the hook box border colour (now the 70% yellow), the card body padding, and the Add concept tile (icon circle, title size). The ones left as VISUAL are the status pill text and the card width, both explained in the table.

### FUNCTIONAL differences for Pedram (left as built)

1. No "Send reminder", "Generate concepts", "Preview as client" or "Share with client" (no backend or sprint for them).
2. Banner button is "Record client's decision" (admin and manager), not "Approve on client's behalf".
3. Stepper step 4 reads "n of m approved". Steps 5 and 6 show "Not available yet" and, only when there is no concept or at least one is not approved, a warning. They are never locked.
4. No per-concept comment count or "Notes & comments" column.
5. No "Add market" or "Script language" on this step.
6. The Approved and Changes requested footers (name, date, note, comment) are built from the plan; the frame does not draw them.
7. The funnel chip opens a menu; the link row has an edit pencil; a dashed drop line shows when a concept has no references.
8. The status pill says "Approved", the frame says "Approved by client" (ruled: stays).

### Real-browser checks (real walk data, Chromium)

| Check                                                                                                                  | Result                                                                                                                                                                                                                               |
| ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Widths 1600, 1440, 768, 390                                                                                            | Viewed. At 390 the page is not wider than the screen **with the menu drawer closed** (Playwright asserts it). With the drawer open, the agency shell leaves about 110 px (existing shell behaviour, same on PL-2.1 and PL-2.2 pages) |
| Open, close and quickly reopen: edit, decision, link, delete confirm, viewer, reset confirm, concept menu, funnel menu | Three speeds each; no stuck overlay, fields empty on reopen. The edit, decision and link dialogs are `persistent`, so Escape does not close them; Cancel does                                                                        |
| Real JPG, PNG and WebP upload to MinIO (presigned PUT, then complete)                                                  | Three tiles, count line "3 of 10 images · 0.0 of 300 MB", three blob images                                                                                                                                                          |
| A text file renamed to .jpg                                                                                            | Refused before any bytes: "This file is not a valid JPG, PNG or WebP image." (header byte check in the browser; the server checks again)                                                                                             |
| Total limit: eight PNGs of about 40 MB                                                                                 | The notice for the eighth ("the images of a concept add up to at most 300 MB") appeared 32 ms after selection, **before any init call** (0 at that moment); seven uploaded, final line "9 of 10 images · 278 of 300 MB"              |
| Enlarge and close                                                                                                      | Viewer opens on click, closes, no stuck overlay                                                                                                                                                                                      |
| Remove an image                                                                                                        | Tile count 3 to 2, live blob URLs 3 to 2                                                                                                                                                                                             |
| Hostile string in title, key point, hook, client name, comment, link title                                             | `<img src=x onerror="window.__pwn=1">` renders as text everywhere; `window.__pwn` never set; no `img[src="x"]` in the page                                                                                                           |
| `http://` link, `javascript:` link                                                                                     | Both refused in the dialog: "Only https links are allowed."; nothing saved                                                                                                                                                           |
| https link                                                                                                             | Anchor with `target="_blank"` and `rel="noopener noreferrer"`; the title shows as text                                                                                                                                               |
| A1 (Approved concept, edit title, Save)                                                                                | Confirm dialog with the clearing text; Cancel: status Approved, title unchanged, edit dialog still open; OK: Awaiting client, new title; a second edit: no question                                                                  |
| Page errors                                                                                                            | None (four `net::ERR_ABORTED` on navigation away from other pages' requests, expected)                                                                                                                                               |

### G3: how images are shown, and how leaks were checked

- **The SPA's auth type (read):** a Sanctum **cookie** session through the shared `http` client (`withCredentials`, XSRF header). A bearer token exists only in the Capacitor creator app, not here.
- **Images are shown from the authenticated endpoint.** An `<img src>` cannot send the cookie to a different origin reliably and must not use a public or signed URL, so `useAuthenticatedImage` fetches the image through `http` with `responseType: 'blob'`, makes a blob URL, and the tile and the viewer use that.
- **Blob URLs are revoked** when the tile is removed, when the card unmounts, when the market tab changes, and when the page is left. A late response that arrives after that creates no URL (a ticket guard).
- **How I checked for leaks:** (1) a unit spec (`useAuthenticatedImage.spec.ts`) with break-reverts W23 and W24; (2) in the real browser an init script wrapped `URL.createObjectURL` and `URL.revokeObjectURL` with a set of live URLs. Three images gave 3 live; removing one gave 2; opening and closing the viewer left the count unchanged; **leaving the market gave 0 live**. Not checked: a long session with many pages, and Safari.

### Playwright (`apps/main/playwright/specs/pl23-concepts.spec.ts`)

Four tests, serial, each with its own agency, brand, wave, persona and concepts made through the API, about 5 to 6 seconds each, at 1440 and 390: the decision with hostile text, the A1 confirmation and no sideways scroll; and the links (http and javascript refused, https opens safely, dialogs reopen at three speeds). Run on a throwaway PostgreSQL database (`pl12_test`, migrated fresh by the suite). Break-revert for the `rel` attribute: red then green (`pl-2-3-break-reverts.md`, W29). **Image upload is not in Playwright**: the suite's media disk is local and the presigned PUT has no route there; it was walked by hand against MinIO.

### Flag-off check

- `node tools/walk/walk.mjs --set agency --compare ../../docs/reviews/ah-115-agency-baseline/baseline.json` (run from `apps/main`): **126 targets identical**, 0 page errors. `--set creator` against `ah-115-creator-baseline`: **146 targets identical**. The walk's DOM hash includes comment nodes. The mock API carries no `waves_enabled`, so these walks are flag-off by construction. (The creator run prints some `HTTP 503` rejects for onboarding targets; they are in the baseline too and the compare is identical.)
- Waves on, strategy off: the Playwright test "strategy off: the wave page has no entry and the workspace address leaves for the wave" (PL-2.1) is unchanged and green with this stage's build; PL-1 behaviour is untouched because the strategy route and the entry button stay behind `strategy_enabled`.

### Gates run (Stage 2)

| Gate                                                                                          | Result                                                                                                   |
| --------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Vitest: `src/core/i18n`, `src/modules/strategy`, `src/modules/waves`, `src/modules/campaigns` | 50 files, 607 tests passed                                                                               |
| `vue-tsc --noEmit`                                                                            | clean                                                                                                    |
| ESLint on `src/modules/strategy`, `src/core/i18n`, the Playwright spec                        | clean                                                                                                    |
| Pint (`--test` on all of `apps/api`)                                                          | passed                                                                                                   |
| Larastan                                                                                      | **17 errors in the Stage 1 seeders and seeder test found and fixed in this stage**; now `[OK] No errors` |
| Pest on SQLite: Strategy, Waves, Audit, Erasure, Console folders                              | 873 passed, 21 skipped (PostgreSQL-only)                                                                 |
| Pest on PostgreSQL (`pl12_test`, port 5435): the same folders                                 | 894 passed                                                                                               |
| Playwright `pl23-concepts.spec.ts` (chromium)                                                 | 4 passed                                                                                                 |
| Walk compare agency / creator                                                                 | 126 / 146 identical                                                                                      |

An earlier Pest run showed 13 and 10 failures in the Audit and Erasure folders. They came from environment variables my shell still exported from the walk server (cache, session and Sanctum settings); with a clean environment all passed. They were not code failures.

### Not run

- The real-browser walk on **WebKit or Firefox**: not run (Chromium only).
- Image upload in Playwright: not run (see above).
- A phone device or the creator app: not run.
- The full Pest suite outside the five folders: not run.
- Production container checks (GD, exif, scheduler, queue): see `production-line-sprints.md`, "PL-2.3: must confirm before `pl` is merged"; **not read** for production.

### Disclosures

- **Writes to the dev MinIO bucket.** The walk uploaded images (and eight 40 MB test PNGs) into the `catalyst-engine-media` bucket on the local MinIO (`localhost:9100`) under the throwaway agency's keys. The cleanup seeder deletes them; run it when done with the walk data.
- **No feature flag was written in a database Pedram uses.** `waves_enabled` and `strategy_enabled` are on only in the throwaway `pl23_walk` database. The seeders never write flags. Every throwaway command printed the database name first.
- No `.env` or secret was printed.

## Walk result and fixes (Pedram, 2026-11-21, `pl23_walk`, steps 1 to 19)

### Rulings recorded

1. The status pill stays "Approved" (the frame says "Approved by client"; the footer carries the name and date).
2. The card width stays fluid until the notes column exists (PL-2.4).
3. Steps 5 and 6 cannot be opened because Creators and Scripts are built in PL-3, not because of concepts.

### D1: the amber warning on steps 5 and 6

Rule (`stepWarning` in `conceptsLogic.ts`): the warning appears only when the wave has no concept, or at least one concept is not approved. All approved: "Not available yet" and nothing else. **Several markets combine by summing** the server's wave totals (`total`, `approved`); a market with no concept adds nothing and is not counted against the others. Awaiting client and Changes requested both count as not approved. Cause of the defect: the old rule asked every market to be complete, and the server returns empty markets as not complete.

| Case (market: total / approved / changes requested)         | Step 4 text     | Steps 5 and 6            |
| ----------------------------------------------------------- | --------------- | ------------------------ |
| No concepts, one market (0/0/0)                             | No concepts yet | warning: No concepts yet |
| No concepts, three markets                                  | No concepts yet | warning: No concepts yet |
| Some approved (3/1/0)                                       | 1 of 3 approved | warning: 1 of 3 approved |
| None approved (2/0/0)                                       | 0 of 2 approved | warning: 0 of 2 approved |
| All approved, one market (2/2/0)                            | 2 of 2 approved | **none**                 |
| One changes requested (3/2/1)                               | 2 of 3 approved | warning: 2 of 3 approved |
| All approved in one market, two empty (2/2/0, 0, 0): Wave 2 | 2 of 2 approved | **none**                 |
| Two markets, both all approved (2/2/0 and 4/4/0)            | 6 of 6 approved | **none**                 |
| Two markets, one all approved, one not (2/2/0 and 3/1/1)    | 3 of 5 approved | warning: 3 of 5 approved |
| Three markets: approved, changes requested, empty           | 2 of 4 approved | warning: 2 of 4 approved |

All ten are Vitest tests (`StrategyStepper.spec.ts`, "D1 combination"). Two break-reverts (`pl-2-3-break-reverts.md`, W30, W31). In the real browser, Wave 2 shows "Concepts | 2 of 2 approved", steps 5 and 6 "Not available yet" only; Wave 1 (1 of 4) shows the amber warning.

### A1: angle suggestions (as built)

In the concept dialog, a lightbulb button inside the Angle field (shown only when the chosen persona has angles) opens a list titled "Angles of {persona}". Each row shows the USP, whether the line was added by hand or synced from the matrix, and the line. Picking one fills the Angle text; the field stays editable and free typing still works. Changing the persona refreshes the list and never changes text already typed. A persona with no angles: the field behaves as before. The server stores the text only; there is no link between a concept and an angle (the payload's `angle` is a string, and a test checks no other angle key is sent).

### A2: Download in the image viewer

A Download button in the enlarge view saves the original under its original file name (path separators and control characters replaced; an empty name becomes "image"). It reuses the blob the viewer already read through the authenticated endpoint: no second request, no presigned URL, no public link. It is off until the image has loaded. Checked in the browser for admin, manager and staff, at 1440 and 390: `walk-graphic.png`, 3846 bytes, from a `blob:` address. Not checked: Safari, and a file larger than the 10 MB walk files.

### P1: decision dialog

The choice is a radio group (`role="radio"`, `aria-checked`): selected is the filled design-system button (green for Approved, red for Changes requested) with a check, the other is outlined with an empty circle. Required: Approved needs the client's name; Changes requested needs the name and the comment. When Save cannot proceed, one line in the error colour says why: "Enter the client's name.", "Enter the client's comment.", "Enter the client's name and the comment.". The line is hidden when Save can proceed or a server error is shown. At 390 the second button wrapped but clipped; fixed (wrapping label, auto height).

### P2: persona in use by concepts

Titled "{name} can't be deleted" (not "Delete {name}?"). Grouped under "Concepts" and "Matrix cells", with the count and the "move or delete them first" sentence kept, and a link "See this persona's concepts". The link opens the Concepts step with `?persona=`; the step shows only that persona's concepts, a line "Showing only the concepts of {persona}." and "Show all". An unknown persona id in the address is ignored. The banner counts stay for the whole market. (This filter is an addition the link needs.)

### CHECK: "I can't log on"

Real sign-in form, a fresh browser each (no cookies, no storage), 8 s apart:

| Account                   | Result                          |
| ------------------------- | ------------------------------- |
| `admin@pl23-walk.local`   | works (login 200, lands on `/`) |
| `manager@pl23-walk.local` | works                           |
| `staff@pl23-walk.local`   | works                           |
| `admin@pl23-walk-b.local` | works                           |

All four work, so the accounts are seeded correctly and I could not reproduce a failure. The most likely cause is the sign-in throttle (`IdentityServiceProvider`): **10 requests per minute per IP on all `/auth/*` routes** (`auth-ip`), and **5 login attempts per minute per email and IP** (`auth-login-email`). Repeated sign-ins in one minute (for example across the roles) reach the per-IP cap and the form then fails until the minute is over; I hit it myself with my walk scripts and a wait of about 65 s cleared it. I did not change the limits. A leftover session for another user is the second possibility. Not confirmed for Pedram's browser.

### Gates run (walk fixes)

| Gate                                                                                          | Result                                                  |
| --------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| Vitest: `src/core/i18n`, `src/modules/strategy`, `src/modules/waves`, `src/modules/campaigns` | 51 files, 627 tests passed                              |
| `vue-tsc --noEmit`, ESLint (strategy, the Playwright spec)                                    | clean                                                   |
| Pint, Larastan                                                                                | passed, `[OK] No errors` (no PHP changed in this round) |
| Pest Strategy, Audit, Erasure on SQLite (clean environment)                                   | 619 passed, 18 skipped (PostgreSQL-only)                |
| Pest Strategy, Audit, Erasure on PostgreSQL (`pl12_test`, port 5435)                          | 637 passed                                              |
| Playwright `pl23-concepts.spec.ts` (chromium), serial                                         | 6 passed, about 33 s                                    |

**Flag-off check: not run, on purpose.** Only files inside `modules/strategy` and the locale JSON were touched; nothing that renders with strategy OFF (the wave page, the entry button, the router guard) changed. The earlier 126/146 identical result stands.

**Not run:** Safari and Firefox; downloading a very large original; a long session for blob leaks after the viewer download.

## Close-out (2026-11-21)

### The walk and the re-check

Pedram walked PL-2.3 locally on `pl23_walk` (19 steps, three roles at two widths, plus the other agency). The result was one defect (D1), two additions (A1, A2), two polish items (P1, P2) and one thing to check (sign-in). Nothing else was reported. All six are fixed and recorded above (the persona filter was added only because the P2 link needs it). **Pedram re-checked the fixes on 2026-11-21 and accepted them.** The three rulings are recorded at the top of "Walk result and fixes". Steps 5 and 6 cannot be opened because Creators and Scripts are built in PL-3, not because of concepts.

### Close-out gates (local, 2026-11-21)

| Gate                                                                                          | Result                                                                                             |
| --------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Pest: Strategy, Waves, Brands, Audit, Erasure, Tenancy, Console on SQLite (clean env)         | 975 passed, 21 skipped (PostgreSQL-only)                                                           |
| Same folders on PostgreSQL (`pl12_test`, port 5435)                                           | 996 passed                                                                                         |
| Vitest: `src/core/i18n`, `src/modules/strategy`, `src/modules/waves`, `src/modules/campaigns` | 51 files, 627 tests passed                                                                         |
| `vue-tsc --noEmit`, Pint, Larastan                                                            | clean, passed, `[OK] No errors`                                                                    |
| ESLint (`src`, `playwright` of `apps/main`)                                                   | 0 errors, 2 warnings (existing)                                                                    |
| Walk compare agency / creator (with comment nodes)                                            | 126 / 146 identical, 0 page errors                                                                 |
| Waves on, strategy off (PL-1 as before)                                                       | `wave-dialog-menus` and `wave-tracker` specs passed (4 tests); the two "strategy off" tests passed |

Playwright, serial, one worker, chromium, throwaway `pl12_test`. Every test is under 15 s:

| Spec                                 | Test                                                  | 1440          | 390    |
| ------------------------------------ | ----------------------------------------------------- | ------------- | ------ |
| `pl23-concepts`                      | decision, hostile text, A1 confirmation               | 5.6 s         | 5.7 s  |
| `pl23-concepts`                      | links, dialogs reopen                                 | 5.3 s         | 5.4 s  |
| `pl23-concepts`                      | walk fixes (stepper, decision, persona refusal, link) | 4.1 s         | 4.7 s  |
| `strategy-matrix-manual`             | prefill, red hint, Use the matrix line                | 4.7 s         | 5.3 s  |
| `strategy-matrix`                    | editor, reopen, hostile text, synced angle            | 4.5 s         | 5.1 s  |
| `strategy-matrix`                    | strategy off: address leaves for the wave             | 1.6 s         |        |
| `strategy-workspace`                 | entry, USPs, dialogs, hostile text                    | 12.7 s        | 14.1 s |
| `strategy-workspace`                 | personas, angles, copy, delete of a USP in use        | 9.5 s         | 10.5 s |
| `strategy-workspace`                 | "removed by someone else" alert                       | 2.5 s         | 2.8 s  |
| `strategy-workspace`                 | strategy off: wave page has no entry                  | 2.6 s         |        |
| `wave-dialog-menus` / `wave-tracker` | 4 tests (run separately)                              | 4.6 to 10.5 s | 11.6 s |

**A stale test was found and fixed here:** `strategy-workspace` (PL-2.1) still expected the Concepts step to be disabled, which has been wrong since PL-2.3 made it a live link. It now expects Concepts live and Creators and Scripts disabled. It was red before the fix and green after; the other 17 were green throughout.

### Clean-up

`Pl23WalkCleanupSeeder` ran on `pl23_walk` (database name printed first): 16 image rows, 0 after; both walk agencies gone. The `media` bucket then held 5 objects, none under a walk concept key (they belong to earlier dev data and were left alone). `pl23_walk` was dropped. The servers on 8003 and 5185 were stopped. `apps/api/storage/app/pl23-walk-files` does not exist and the path is git-ignored (`storage/app/.gitignore`), so nothing about it is committed. `/tmp/pl23` was left for Pedram.

Flags in Pedram's database `catalyst` (read only, a SELECT with a read-only session): **`waves_enabled` = true and `strategy_enabled` = true** (global). They were not written by this work.

### Not run

- Safari and Firefox; a phone device; the creator app.
- Image upload in Playwright (the suite's media disk is local); it was walked by hand against MinIO.
- Downloading a very large original in the viewer.
- The staging walk (there is no staging).
- The full Pest suite outside the seven folders.
- The production container checks in `production-line-sprints.md` (GD and exif, the scheduler, the queue): **not read** for production.

## Walking it yourself

See the end of the report in chat for the exact commands, addresses and logins.
