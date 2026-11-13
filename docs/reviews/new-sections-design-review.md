# New sections: design and brief review

**Date:** 2026-11-12. **Status:** understanding only. No decisions taken, nothing planned or built.
**Sections:** Strategy & script writing, Video editing, Client portal.

**Sources read**

- `How Catalyst works (the simple version)` (Word).
- `Strategy & Scripts: Developer Brief v2`, Jordan, 1 Oct 2026 (Word).
- `~/Desktop/screenshots`, three folders, 123 PNG files: 54 unique screens, 9 reference copies, and 60 annotation labels and flow notes. Every screen was viewed and its text extracted.
- The 9 `REF` screens in the Client portal folder are pixel-identical copies of screens in the other two folders (checked), so they are described once.

**Not seen:** the Figma file itself, the clickable prototype, hover and error states that are not in the screenshots, and the style guide Jordan mentions.

---

## 1. The concept

Catalyst makes UGC ads for brands. Today the work runs across Figma, Asana, Google Sheets, email and file links. The three sections move the whole production line into the platform, so one record carries an ad from the client's first brief to the approved video.

The line, end to end:

1. **Client onboarding.** The client joins a portal, fills in a questionnaire and uploads files.
2. **Strategy.** Per wave: USPs, personas, a messaging matrix, concepts. The client approves the concepts.
3. **Creators and scripts.** Creators are picked from the existing creator base and matched to a persona. Scripts are written per creator. The client approves each creator and each script.
4. **Filming.** The platform sends the script to the creator's app. The creator films and uploads one clip per hook, line and B-roll shot.
5. **Footage review.** The strategist approves clips, asks for re-films, and accepts or rejects wording the creator changed.
6. **Editing.** An editor takes the approved footage and script, edits outside the platform (Adobe), and uploads the final ads. An AI agent checks the subtitles against the approved script.
7. **Client ad review.** When every ad of the month is complete, the batch goes to the client. The client comments at exact moments in each video and approves or requests changes, then submits all feedback at once.
8. **Revision.** The editor fixes the flagged ads and uploads V2. The client reviews V2 on the same link.
9. **Library.** Approved ads collect in the client's ads library, ready to download.

**What the design is trying to achieve**

- One fixed structure for every brand and wave.
- Later steps reuse earlier data instead of copying it. A script knows its persona, USPs and concept. A finished ad knows its script, creator, persona and USPs.
- Strategists do strategy. The platform does the chasing: briefs, deadlines, reminders, collecting footage.
- The client has one place for every approval, with their name on every comment and decision.
- AI is a layer on top. Every AI output is labelled, editable, and never overwrites a person's edits.

**The three gates**

1. Creators and Scripts stay locked until the client approves every concept in that market.
2. The script goes to the creator only when the client has approved the creator and all of that creator's scripts.
3. Strategists (and then editors) come back in when footage arrives.

**The main objects**

| Object         | Belongs to      | Notes                                                                 |
| -------------- | --------------- | --------------------------------------------------------------------- |
| Brand          | Agency          | Already exists. Gains client files, waves, a portal.                  |
| Wave           | Brand           | One batch of ads. Name, go-live date, markets.                        |
| Market         | Wave            | A country plus its script languages (English plus one or two local).  |
| USP            | Wave            | 4 to 6, shared by all markets. Fixed colour each.                     |
| Persona        | Market          | 2 to 8 per market.                                                    |
| Matrix cell    | USP × persona   | Level (Priority, Support, Low), rationale, message line.              |
| Concept        | Market          | 3 or more. Client approval per concept.                               |
| Wave creator   | Market          | An existing creator plus slot code, persona, status, client approval. |
| Script         | Wave creator    | Hooks, lines, B-roll shots, reference images. Versions and snapshots. |
| Clip           | Script section  | One per hook, per line take, per B-roll shot.                         |
| Amendment      | Script section  | What the creator actually said, with accept or reject.                |
| Editing job    | Script          | Approved footage plus final script, assigned to an editor.            |
| Ad             | Editing job     | A final video (one per hook), with versions V1, V2.                   |
| Monthly batch  | Brand and month | All ads of the month, sent to the client together.                    |
| Review         | Batch           | Reviewers, per-ad decision, timecoded comments, drawings.             |
| Revision round | Batch           | The ads with changes requested, to fix.                               |
| Client file    | Brand           | Brief, research, guidelines, assets, performance data, questionnaire. |
| Portal member  | Brand           | A client person: name, email, role, permission, remembered browsers.  |

---

## 2. Strategy & script writing (15 frames)

It lives under **Brands > [brand] > [wave]**. No new sidebar item.

**Shared workspace header (steps 1 to 6)**

- Breadcrumb, wave title, wave status pill ("Scripts in client review", "Footage in review").
- Buttons: **Preview as client**, **Share with client**.
- Stepper with six steps, each showing its own status.
- Market bar on steps 2 to 6: a tab per market, "+ Add market", the script languages.
- Right panel on every step: internal **Notes** (amber) and **Comments** (client plus team, each anchored to an element, with Reply and Resolve).
- An **Approval** dropdown per step.

### 01 · Brand page

- Left card: brand details (name, slug, status, monthly deliverables, industry, website, created). Edit and Archive.
- **Client files panel:** Send questionnaire, Upload files.
  - Pinned questionnaire row: "Added automatically", status, who submitted and when, questions answered, View responses.
  - File table: name, format and size, type chip (Brief, Research, Guidelines, Assets), uploaded by with a Client or Catalyst tag, date, row menu.
  - Usage tag on a file, for example "Used for USP import".
  - Drag-and-drop zone: PDF, DOCX, XLSX, images, ZIP, up to 100 MB.
- **Waves panel:** New wave button. Each row: name and go-live date, market chips, six-segment progress with the current step named, status pill, last updated.

### 01b · Wave project tracker (not in the brief)

- Wave-level tabs: **Project tracker, Strategy & Scripts, Editing, Statics (Figma), Stills, Files.**
- Wave health pill ("At risk · 1 stream delayed"). Buttons: Share status update, Add task.
- Three stream cards: **UGC video, Statics, Stills.** Each shows on track or delayed, counts, step progress, next step, final delivery date, owners, and a link (Strategy & Scripts, Figma, shoot folder). A delayed card opens a popover that says why.
- **Deliverable steps table:** filter All, UGC, Statics, Stills, Mine. Columns: step, owner, due, status, open in. Completed steps collapse. "Add step to a stream".
- A delayed step expands into **What needs fixing:** who flagged it, what blocks it, the impact on dates, how to recover, with Move due date and Reassign.
- Right column: Needs attention, Due this week, Team on this wave with capacity ("3 waves this week").

### 02 · Step 1, USPs

- One editable audience title.
- Up to six USP cards: number, title, explainer, creative angle, copy seed directions, source footer ("Client brief", "Client brief, built out by Jess"), comment count.
- **Import from brief** (AI). **Add USP** is disabled at six.
- Drag to reorder. A fixed colour per USP, reused everywhere.

### 03 · Step 2, Personas (per market)

- Persona card: code chip (SK1), title, name, avatar, short description.
- Fields: Core job, Emotional drivers, Values and goals, What she or he tells herself or himself (pronoun-aware), What they need, Barriers and frictions.
- **Creative angles that unlock:** USP chip plus one line, marked "Synced from matrix". "Add angle from USPs" lists every USP and marks linked ones.
- Copy from another market or wave. Add persona slot shows "2 of 8 used in Slovakia".

### 04 · Step 3, Messaging matrix

- Table: USPs as rows (pinned), personas as columns with "Lead with: …".
- Each cell: level dropdown, "Agent" or "Edited by Jess" badge, one-line rationale, message line.
- Agent bar: source counts, when generated, cells edited, Show reasoning, Regenerate, "Keep my edits" toggle.
- Legend for Priority, Support, Low.

### 04b · Matrix agent states

- Empty (Generate, with readiness checks), Generating ("7 of 12 cells", user can leave), Out of date (names what changed and who), Cell override.

### 05 · Step 4, Concepts

- Actions: Generate concepts (AI), Add concept.
- **Approval banner:** date sent, counts, Send reminder, Approve on client's behalf.
- Concept card: number, client status, an "R2" marker (probably revision round 2), title, persona, funnel stage, USP chips, Product, Angle, Primary message, Hypothesis, Hook, Format / visual, Key points, Payoff, CTA, references (images and links).
- A card with changes requested shows the client's comment underneath.
- Stepper shows "Client review · 1 of 3". Steps 5 and 6 are locked.

### 06 · Step 5, Creators (per market)

- Summary row: totals per status and creators per persona.
- Creator card: photo, slot badge, name, verified tick, city, languages, categories, Example content, **Persona** dropdown, **Status** dropdown (Shortlisted, Contacted, Confirmed, Declined), Profile, Replace, Remove.
- Declined cards are faded. An "Add creators" tile opens the picker.

### 07 · Add creators picker (modal)

- Reuses the Discover grid: search, removable Country and Language chips, Category and Talent pool filters, result count.
- Multi-select. "Already in wave" creators cannot be picked.
- Footer: who is selected, optional "Assign persona", Add.

### 08 · Step 6, Script editor

- **Left rail:** scripts grouped by creator with status, "Add script (1/3)", "Generate first script".
- **Title row:** creator and script number, last edited and version, Regenerate, language toggle (EN, SK, Side by side).
- **Workflow bar:** Draft > Internal review > Client review > Approved, who it waits on, the main action.
- **Brief (auto-filled):** persona, USPs with level, angle, duration with an estimate from the lines, "Edit in step 3".
- **Hooks A, B, C:** audio hook per language, visual hook (shot type plus description), optional overlay per language, reference images.
- **Script lines:** text per language, B-roll rows (code, shot type, description), reference images, CTA flag, per-line client comment count.
- Shot types: Wide, Medium, Close-up, Detail, POV, Over the shoulder, Top-down, Screen recording, Reaction, Creator to camera.
- "Auto-translated · review" label on machine translation. Lines collapse to one row.

### 09 · New wave (modal)

- Wave name, go-live date, markets.
- **Copy from a previous wave:** choose USPs, Personas, Messaging matrix (regenerated), Creators (reset to Shortlisted). Scripts are never copied.
- **Start fresh.**

### 10 · Client view, creators and scripts

- Portal top bar. Six section tabs with the client's own status ("Approved by you", "3 of 4 approved", "3 of 5 to review"). Market switch.
- **Creator card:** photo, slot, name, city, age, language, tags, matched persona, example content, View profile. Buttons: **Approve creator**, **Suggest another**.
- **Script, read-only:** **Approve script**, **Request changes**. Hooks, lines and B-roll in both languages. Reference images open full size.
- Comments panel with Open and Resolved counts. The client can comment on any line or image.
- "Other scripts to review" list.

### 11 · Client view, concepts approval

- Each concept read-only, with references.
- Card footer by state: Request changes, Approve concept, Comment; or "Approved by you · Sep 15" with Undo; or "You requested changes. Catalyst is revising."
- Right panel "Your approval": progress bar, each concept's status, the unlock note, comments.

### 12 · Creator app, script and footage upload (10 phone screens)

- **12a New script arrives:** push notification, same message by email.
- **12b My scripts dashboard:** counters (To film, Due this week, Re-films), footage progress, week strip, Coming up, scripts list with progress, latest messages. Bottom tabs: My scripts, Messages, Payments, Profile.
- **12c First open:** message from the strategist, the brief (who you are, the story, key messages), what to film (hooks, lines, B-roll counts), due date, length, format, how it works, Open script, "Can't make the due date?".
- **12d Script overview:** clip progress ("9/19 clips"), note from Catalyst, each hook and line with its clip count and an "Amended" tag, Submit for review (locked until every slot has a clip).
- **12e Line detail:** the original line, the switch **"I amended this line slightly"** and a box for what was actually said, the take-to-camera clip, each B-roll shot with its own upload slot and reference image.
- **12f Hook detail:** what to say, what to show, on-screen text, reference images, agency comment with Reply, the clip, Next and Back.
- **12g Add clip sheet:** Record now, Choose from camera roll, Browse files. Vertical 9:16, 1080p or 4K, MP4 or MOV, up to 2 GB.
- **12h Upload slot states:** Empty, Uploading, Uploaded, Failed (Retry, Choose another), Format check (horizontal clip: Replace or Keep anyway), Re-film requested, Approved (locked).
- **12i Submitted:** "Catalyst will review within 48 hours", summary, list of amended sections.
- **12j Changes requested:** which clips need a re-film and why, whether each amendment was accepted, Upload new take, Resubmit, new due date.

### 13 · Agency, footage and amendment review

- The script page gains a **Script | Footage** switch. The workflow bar becomes Script > Filming > Footage review > Client review > Approved, with **Send to client**.
- Filters: All sections, Amended by creator, Needs re-film, Unreviewed.
- **Amendment card:** original line, what the creator said with the changed words marked, an automatic English back-translation, **Accept amendment**, **Ask to use original**, Comment.
- **Clips per section:** take to camera plus one per B-roll shot, each with **Approve** and **Re-film**.
- Right panel: review counts (approved, re-film requested, amendments to review, format warnings), creator amendments list, note from the creator with translation.
- An accepted amendment replaces the script text the client sees, tagged "Amended by creator".

---

## 3. Video editing (19 frames)

Editors log in to a **separate workspace** with an EDITOR badge and their own sidebar: My jobs, Open pool, Monthly deliverables, Revisions, Files, Messages, Settings. They see no strategy or creator tools.

### 01 · Editor, My jobs dashboard

- Four counters: Assigned to me, Ready to edit, Agent flags to review, Client revisions.
- **Assigned to me table:** job (creator and script, brand, wave, month), market, deliverables ("3 ads · 9:16"), stage (Ready to edit, Editing, Agent flags, Client changes, Submitted), due date and how it was assigned. Search and stage filter.
- **Open pool card:** unassigned jobs with **Claim**.
- **Monthly deliverables card:** progress per brand and month.

### 02 · Job brief, script and footage

- Stepper: Brief received > Footage approved > Edit > Upload and agent check > Client review.
- **Deliverable spec:** ads to deliver ("3: Hook A, Hook B, Hook C plus shared body"), format, length, subtitles, placement and safe zones, end card.
- **Editing notes from the strategist.**
- **Script and approved footage:** per section, the audio line in both languages, the visual note, the on-screen text rule, the approved clips with download, and which ad each section belongs to.
- **Files for this job:** approved footage, music, brand kit, brief and script. "Download everything" includes a Premiere Pro project with clips pre-sorted. "Open in Adobe".
- Creator card (filming date, device). Message the strategist.

### 03 · Upload final ads

- One upload slot per expected ad, with progress.
- File naming rule (CREATOR_MARKET_HOOK_V#). Versions are tracked on re-upload.
- **Automatic checks:** resolution, duration, audio peaks, burned-in subtitles detected, subtitles match approved script.
- Save and finish later. Run agent check.

### 04 · Agent subtitle check

- A tab per ad with its result (All clear, 3 flags, Checking).
- Player with the subtitle area marked and flag markers on the timeline.
- Counts: lines checked, matched, flagged.
- **Each flag:** timecode, type (Spelling, Wording, Timing), approved script against on-screen text. Actions: **Upload fixed version** or **Mark as intentional** with a reason shared with the strategist.
- Download flag list (CSV), Re-run check, Submit this ad anyway (open flags go to the strategist), Submit all ads.

### 05 · Monthly deliverables, batch progress

- One page per brand and month ("18 ads from 6 creators, client review due 24 Oct").
- Banner: the batch goes to the client only when all ads are complete, with a link to the open items.
- Per creator: each ad with file name, status (Complete, Agent flags, Editing) and agent result.
- Right: batch totals by status, **Send batch to client** (locked until complete), Preview as client, Download all.

### 06 · Revision round

- Left: the ads of the round, each marked Changes or Approved.
- Middle: player with the client's drawing on the frame, and a "V1 > V2 upload" marker.
- Right: the client's timecoded comments with tick boxes, a note from the strategist, **Upload V2** (the agent re-checks).
- Download comments as XML for Premiere. Resubmit batch ("0/5 fixed"). Due date.

### 07 · Strategist, send batch to client (modal)

- Reviewers by name or email, each with a permission ("Can approve").
- Optional message.
- Link settings: remember reviewers for 90 days, anyone with the brand's email domain can review after a one-time code, allow downloads, link expiry.
- Copy link. Send.

### 08 to 11, 13 · How the client gets in

- **08 Invite email:** thumbnails, counts, review-by date, one button. No account or password.
- **09 Who's reviewing:** full name, work email (checked against the invite list), optional role, "Remember me on this browser for 90 days".
- **10 Verify email:** six-digit code, only for an email not on the list or a new browser.
- **11 Returning visitor:** "Welcome back, Anna", progress so far, Continue, "Not you?", Forget this device.
- **13 Reviewer menu:** edit name and role, email me a link for another device, switch reviewer, forget this device.

### 12, 14 to 17 · Client review of the ads

- **12 Batch overview:** one folder per creator with ad count and status. Group by. Download all. A bottom bar shows progress and **Submit feedback to Catalyst**, disabled until every ad is reviewed.
- **14 Creator folder:** each ad with version, length, comment count, download, and a status dropdown (Approved, Changes requested, Not reviewed).
- **15 Player, Comments tab:** Approve or Request changes. Comments pinned to a timecode, with drawing on the frame, attachments, replies from Catalyst. Markers on the timeline. Loop, speed, volume, quality, full screen.
- **15b Player, Script tab:** the approved script with a time range per line, SK, EN or both. Click a line to jump. Comment on a line.
- **15c Player, Details tab:** reviewers on this ad and their status, file and version, creator, market, persona, hook, USPs, quality checks, version list.
- **16 Submit:** the button enables at 18 of 18 reviewed.
- **17 Feedback sent:** totals, what happens next (strategist reviews notes, editor makes changes, client reviews V2 on the same link), View my comments, Download approved ads.

---

## 4. Client portal (19 own frames, 1 component, 9 reference copies)

**One portal per brand.** The client identifies themselves once and the browser is remembered for 90 days. Every approval then happens inside the same portal with the same name attached. All client screens share one top bar: **Home, Strategy & scripts, Ads, Files, Team**, plus a bell and the person's menu.

### P01 to P03, P07 · Joining

- **P01 Invite email:** welcome, first step (the questionnaire, about 15 minutes), Open your portal.
- **P02 Who are you:** name, work email, role, remember me. Done once.
- **P03 Verify email:** six-digit code for people who were not invited. Only the brand's email domain can join this way. The strategist is notified and can remove access.
- **P07 Welcome back:** on a remembered browser, on any link.

### P04a to P04j · Onboarding questionnaire

- Eight sections, 35 questions, a progress bar, autosave ("Saved 2s ago"). Any invited colleague can continue it.
- **1 Business snapshot:** primary contact, who signs off (they become "Can approve"), who handles contracts and invoices.
- **2 Commercial goals:** primary goals, target CPA, target ROAS, monthly spend range, current or planned. Targets are shown to the strategist on every concept.
- **3 Audience truths:** primary and secondary audience, pain point, emotional driver, one sentence on why customers choose them.
- **4 Brand & voice:** how the brand should feel (up to 3), a "how provocative" slider, red lines (they become brand guardrails), brand guidelines upload, tone of voice upload.
- **5 Product & offer:** products with links, core benefit, key USPs (they become the starting USP cards in step 1), pricing, offers, top 3 objections.
- **6 Performance history:** active channels, what worked, what did not, the biggest mistake to avoid.
- **7 Proof & constraints:** claims that can be used, proof upload, legal and compliance limits.
- **8 Access & assets:** Figma link, existing ads, imagery, logos and fonts upload. Performance access: Catalyst's partner ID, engagement type, Meta ad account ID, TikTok Business Center ID, a confirmation tick.
- **Review & submit:** each section with Complete or Needs answer and Edit. It can be submitted with an answer missing.
- **Submitted:** what happens next, Book kick-off call, Go to portal home.

### P05 · Portal home

- **Needs you:** a list of what waits for the client (review ads, approve concepts, upload requested files), each with progress and one button.
- A line for the completed questionnaire. Recent activity.
- **Your waves:** each wave with an eight-segment progress bar: Strategy, Concepts, Creators, Scripts, Filming, Editing, Ads review, Live.
- **Your Catalyst team** with a message icon per person. Invite a colleague.

### P06 · Wave tracker

- Eight-step stepper with the client's status per step. Market switch.
- Six cards: Strategy (steps 1 to 3), Concepts, Creators & scripts, Filming & editing ("Nothing needed from you", See what was filmed), Ads review, Go live (Open ads library).
- **Key dates** list.

### P08 · Files

- **Shared by the brand:** table with type, uploader, date, download, row menu. Upload.
- **From Catalyst:** wave strategy and concepts (PDF export), approved scripts, approved ads.

### P09 · Team & access

- Table: person, role, permission (**Can approve** or **Can comment**), last active, how they joined.
- **Your remembered browsers** with Forget.
- **Invite a colleague:** name, work email (domain checked), permission. The strategist is told.

### P10 · Ads library

- Every approved ad as a card: hook, version, creator, market, download.
- Filters: month, market, creator, hook. "Download October · 13 ads".
- Each ad keeps its script, creator, persona and USPs.

### Reference copies

Strategy frames 10 and 11 and Video frames 12, 14, 15, 15b, 15c, 16, 17 are repeated here to show the flow. The originals stay the source of truth.

---

## 5. Where the documents and the designs disagree

The brief is dated 1 Oct. The designs are newer and go further. Each point needs one ruling.

| #   | Topic                            | Brief or simple doc says                | Designs show                                                                                                        |
| --- | -------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| 1   | Editing                          | Out of scope for this phase             | A full editor workspace and client ad review                                                                        |
| 2   | Creator brief and footage upload | "Not designed yet"                      | Designed (frame 12), plus agency footage review (frame 13)                                                          |
| 3   | Client access                    | Magic link or SSO, "View and comment"   | No account: name and email once, a 90-day browser cookie, a six-digit code, permissions Can approve and Can comment |
| 4   | Scripts per creator              | 3 is the maximum                        | "Up to 3 by default, add more if needed"                                                                            |
| 5   | Questionnaire size               | 24 questions (brand page, portal Files) | 35 questions (questionnaire screens)                                                                                |
| 6   | PDF export                       | Out of scope                            | Portal Files lists "Wave 7 strategy & concepts (PDF export)"                                                        |
| 7   | What the client approves         | Simple doc: concepts, creators, scripts | Client tabs show USPs, Personas and Matrix as "Approved by you"; the tracker says "Strategy approved by you"        |
| 8   | Who approves footage             | Strategist picks it up                  | Frame 13 has "Send to client" and a "Client review" stage; the portal says "Nothing needed from you" for filming    |
| 9   | Unit of work                     | Wave                                    | Editing and client ad review run on a monthly batch per brand                                                       |
| 10  | Other streams                    | Not mentioned                           | The tracker has Statics and Stills streams with their own tabs                                                      |
| 11  | Creator payments                 | Out of scope                            | The creator app has a Payments tab                                                                                  |
| 12  | Creator hand-off trigger         | Automatic after client approval         | Frame 12a says "the moment the agency sends the script"                                                             |

---

## 6. Pages and states with no design

### Agency (strategy and footage)

- Add market, remove market (typed confirmation), reorder markets.
- Import from brief: choose file, preview the drafted USPs, insert.
- Delete USP confirmation listing what is affected.
- Matrix "Show reasoning".
- Generate concepts and generate script: progress, preview, error.
- Out-of-date banners on concepts and scripts.
- Script version history and the snapshot the client was sent.
- Locked state of steps 5 and 6.
- **Share with client** for strategy (who is invited, what is shared). Only the ads version exists (Video 07).
- Approve on client's behalf: the reason dialog.
- Send questionnaire dialog, and the agency view of questionnaire responses.
- **Brand guardrails** editor (do's and don'ts), and "Add to guardrails" on a comment.
- Creator card after hand-off: status (Brief sent, Viewed, Acknowledged, Filming, Footage uploaded, Overdue), pause, resend, edit dates.
- Deadline rules: how dates are worked back from go-live, and where they are set.
- Remove a creator who has scripts (archive or delete).
- Outreach Copilot list. Campaign set-up "Review and generate".
- Footage review: the re-film request note, a full-size clip player, format warnings.
- **Hand-over to editing:** where the strategist sets the deliverable spec and editing notes, assigns an editor or sends the job to the pool.
- **The strategist's Editing tab:** editor progress, the agent's open flags and overrides.
- **The strategist's triage of client feedback** before it reaches the editor (frame 17 says "Jess reviews your notes"; frame 06 shows "Note from Jess").
- Project tracker dialogs: Add task, Add step, Share status update, Move due date, Reassign. The Statics, Stills and Files tabs.

### Creator app

- Where "My scripts" sits beside the current app (Dashboard, Availability, Job Posts, Invitations, Assignments).
- Acknowledge the brief. Decline. Ask a question. "Can't make the due date?".
- View submission. The fully approved end state. In review and Done lists. Calendar.
- The creator's side on the web (creator web now mirrors the app).

### Editor workspace

- How an editor gets an account, and who manages editors.
- Open pool page. Monthly deliverables list. Revisions list. Files. Messages. Settings.
- Job states not shown: Submitted, waiting on batch, done.

### Client portal

- **Client approval of USPs, Personas and Matrix** (see point 7).
- Request changes on a concept or script: the dialog. Suggest another creator: the dialog.
- Strategy & scripts landing page (list of waves). Ads landing page when no batch is in review.
- "See what was filmed".
- Reviewing V2: what changed against V1.
- What a "Can comment" person sees (no approve buttons).
- Link expired, access removed, wrong email domain, downloads switched off.
- Notification panel and the notification emails.
- View and edit questionnaire answers after submitting.
- Book kick-off call (which calendar).
- Messaging the Catalyst team.
- **Phone layouts.** The designs are desktop only, and the Team page lists "Safari on iPhone".

### Admin and system

- Brand settings for the portal: allowed email domain, link expiry, downloads.
- Agent run log (prompt, model, output), which the brief requires.
- Feature flags for each section.

---

## 7. Technical points the designs imply

These are not decisions. They are the heavy parts to size before planning.

- **Video handling at a new scale.** Clips up to 2 GB in 4K from phones, resumable background upload, thumbnails, streaming playback, 2.4 GB zip downloads, final ads with versions.
- **The subtitle agent.** It needs text recognition on video frames, speech-to-text for the audio, and a comparison with the approved script per language.
- **Frame-accurate review.** Timecoded comments, drawing on a frame, comment export as XML for Premiere, a generated Premiere project.
- **AI agents.** Which model provider, cost per run, where client data is processed, run logs, background jobs with progress.
- **A new identity type.** Client reviewers have no account: email, cookie, one-time code. This touches privacy, erasure, audit and the security review.
- **A new role.** Editors: internal staff or freelancers, and what they can see across agencies and brands.
- **Overlap with what exists.** Campaigns, assignments, invitations, the draft review flow, agency Files, campaign attachments, the creator app tabs, messaging. The brief says a wave "can start campaign set-up", so the link between a wave and a campaign must be defined.
- **Themes and languages.** The designs are dark only; the agency app has dark and light. Interface text needs all 24 locales; the portal language is not stated.
- **Notifications.** A large new set, in-app and by email, for four audiences.

---

## 8. Questions to settle first

1. Is the brief or the design the source of truth where they differ (section 5)?
2. Does the client approve USPs, personas and the matrix, or only see them?
3. Does the client review raw footage, or only finished ads?
4. What is a wave in the current data: a new object, or a campaign?
5. How does a monthly batch map to waves?
6. Are Statics and Stills in scope, or only shown on the tracker?
7. Who are the editors, and how do they get access?
8. Are more than 3 scripts per creator allowed?
9. Which comes first: Strategy & Scripts alone, or all three together?
10. Is the client portal needed on phones for the first release?

---

## 9. Rulings (Pedram, 2026-11-13)

| #   | Question                                  | Ruling                                                                                                                                                                                    |
| --- | ----------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Brief or design where they differ         | **The design is the source of truth.**                                                                                                                                                    |
| 2   | Client approval of USPs, personas, matrix | **Yes, the client approves them.** Screens for this are not designed yet.                                                                                                                 |
| 3   | Raw footage                               | **The client reviews finished ads only.** "Send to client" and the "Client review" stage on frame 13 need a new meaning or removal.                                                       |
| 4   | Wave and campaign                         | **Same thing, probably:** a wave is a campaign, possibly a new kind of campaign with different board columns. To confirm by a read-only inventory of the current campaign and board code. |
| 5   | Monthly batch and waves                   | **A month can hold two waves.** So the monthly batch is its own object (brand plus month) and can carry ads from more than one wave. Still open: how an ad is placed in a month.          |
| 6   | Statics and Stills                        | **Tracked only.** The tracker shows their steps, owners, dates and a link out. The work stays in Figma and the shoot folder. No Statics or Stills screens are built.                      |
| 7   | Editors                                   | **Agency staff** (people of the agency, such as Catalyst). Access path still to define.                                                                                                   |
| 8   | More than 3 scripts per creator           | **Allowed.**                                                                                                                                                                              |
| 9   | Order                                     | **All three sections together.**                                                                                                                                                          |
| 10  | Portal on phones                          | **Not needed for the first release.**                                                                                                                                                     |
| 11  | Share link and portal                     | **One client portal only.** The share-link sign-in screens (Video 08 to 11, 13) are not built; a share link opens a page inside the portal.                                               |

**Note on 5, where the monthly batch appears.** Video editing frames 01, 02, 05, 06, 07 and 12, and portal frames P05 and P10. Frame 05 is titled "Bolt Food — October deliverables" with the line "Wave 7 · SK + RO · 18 ads from 6 creators". Job rows read "Bolt Food · Wave 7 · Oct deliverables" and "Glovo · Wave 2 · Nov deliverables". Every example pairs one month with one wave. No screen shows a month holding two waves or a wave spanning two months.

**Note on 6, where Statics and Stills appear.** Only on Strategy frame 01b, the wave project tracker: two of the six wave tabs ("Statics" with a Figma tag, "Stills"), two of the three stream cards, and their rows in the steps table. They link out to Figma and to a shoot folder. No screen exists for the tabs themselves.

**Note on the portal merge.** The designers' own note on the portal page says the same: one portal per brand, the review screens stay where they are, and every client screen uses the portal top bar. Video frames 08 to 11 and 13 (invite email, who's reviewing, verify, welcome back, reviewer menu) duplicate portal frames P01 to P03, P07 and P09. Build the portal versions once. What carries over from the share-link design: the "ads are ready" email (as a notification that opens the portal page), the strategist's choice of who is notified, and the downloads setting.
