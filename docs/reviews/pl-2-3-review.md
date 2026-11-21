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
| Pill text                                | "Approved by client"                                                                         | "Approved"                                                                                                                           | VISUAL, left         | The frame's text names the client; the footer carries "Approved by {name} · {date}" (item 3). Pedram may rule                |
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
| Stepper, steps 5 and 6                   | lock icon, "Unlocks after concepts"                                                          | no lock, "Not available yet"; the warning with counts when concepts are not all approved                                             | FUNCTIONAL           | Ruling: never locked (PL-3 builds them); item 1                                                                              |
| Comment count with icon on the head      | message icon 13 and "1" in amber, dots menu 16                                               | dots menu only                                                                                                                       | FUNCTIONAL           | Per-concept comments are not part of PL-2.3; the comment is the client's one decision comment, shown in the footer           |
| "Notes & comments" column                | 300 wide, right of the grid                                                                  | absent                                                                                                                               | FUNCTIONAL           | As on the USP and persona steps                                                                                              |
| Card width                               | 444 (two cards per row beside the 300 column)                                                | fluid: 620 at 1600 wide (two per row)                                                                                                | VISUAL, kept         | Follows from the missing right column; fluid grid as on the other steps                                                      |
| Add concept tile                         | dashed, centred, plus icon, "Add concept", caption                                           | dashed 1px (46,46,51), pad 24, gap 10, 160 high; plus in a 46px circle at 12% primary; title 15 / 600 (62,207,158); caption 12 / 400 | matches              |                                                                                                                              |
| Market bar                               | "MARKET" label, tabs with code chip, "Add market"; "Script language" on the right            | tabs with code chip and count; no "Add market"; no script-language line                                                              | FUNCTIONAL           | As on the matrix step (Add market and Script language were ruled out in PL-2.1)                                              |

**VISUAL differences found in this pass and fixed:** the reference tile (size, caption, remove button position), the references block layout (title row, button shape, link rows), the banner (padding, font, border at 40%), the hook box border colour (now the 70% yellow), the card body padding, and the Add concept tile (icon circle, title size). The ones left as VISUAL are the status pill text and the card width, both explained in the table.

### FUNCTIONAL differences for Pedram (left as built)

1. No "Send reminder", "Generate concepts", "Preview as client" or "Share with client" (no backend or sprint for them).
2. Banner button is "Record client's decision" (admin and manager), not "Approve on client's behalf".
3. Stepper step 4 reads "n of m approved". Steps 5 and 6 show "Not available yet" and, when concepts are not all approved, a warning with the counts. They are never locked.
4. No per-concept comment count or "Notes & comments" column.
5. No "Add market" or "Script language" on this step.
6. The Approved and Changes requested footers (name, date, note, comment) are built from the plan; the frame does not draw them.
7. The funnel chip opens a menu; the link row has an edit pencil; a dashed drop line shows when a concept has no references.
8. The status pill says "Approved", the frame says "Approved by client".

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

## Walking it yourself

See the end of the report in chat for the exact commands, addresses and logins.
