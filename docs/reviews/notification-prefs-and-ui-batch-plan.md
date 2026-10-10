# AH-101 — plan-pause (notification preference matrix + completion notice + third UI batch)

**Status:** Executed. This is the plan as stated before the build began, kept as a record; the result
and the evidence are in [`notification-prefs-and-ui-batch-review.md`](notification-prefs-and-ui-batch-review.md).
Inventory it was cut from: [`notification-prefs-and-ui-batch-inventory.md`](notification-prefs-and-ui-batch-inventory.md).

## Shape (ratified at kickoff)

One chunk, two commit pairs, **push held, Pedram's device walk is acceptance**.

- **Half A (behavioral):** A1 the backend email guard → A2 the completion notice → A3 the matrix UI →
  A4 the ripple (specs, docs). **A1's both-polarity break-revert is the chunk's centre.**
- **Half B (native, SPA-only):** U1 bell closes on route change → U3 native thread height → U4 composer
  attach controls → U5 onboarding hamburger removal → **U2 sign-in + splash last, behind a device walk**.
- The matrix is built so that **push (AH-102) is one column constant plus one enum case**.
- Out of scope, unchanged: the mail-inside-transaction hazard (`docs/tech-debt.md`), a real social adapter,
  AH-102's push worker, and any change to _who_ receives a mail.

## Sub-steps

| Step | What                                                                                                          | Proof it is done                                                                             |
| ---- | ------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| A1   | `NotificationService::isEmailEnabled(User, NotificationType)`, called at every typed mail site                | Both-polarity test over all 15 sites; scan-based architecture test; existing suites unedited |
| A2   | `assignment.live_verified`: creator in-app (ungated) + mail behind a default-OFF flag, 24 locales             | Flag-off/on/opt-out tests; real-render test in all 24 locales                                |
| A3   | Preferences page → channel matrix (grid web, stacked cards native), columns derived from the registry         | Registry spec pins the email cells to the guarded set; page spec for grid + cards            |
| A4   | Parity specs, the first Playwright walk for preferences, docs                                                 | Parity specs green; walk written (see the review for its run status)                         |
| U1   | `NotificationBell` closes on route change                                                                     | Spec with a break-revert                                                                     |
| U3   | Native-scoped `.rel-thread` height from the layout vars, not the 140px constant                               | Source-inspection pin; device verification                                                   |
| U4   | Native attach controls (paperclip + link inline in the relationship thread, paperclip only in the chat panel) | Specs both polarities, web unchanged                                                         |
| U5   | Remove the native onboarding hamburger and drawer                                                             | Native spec updated; web byte-identical                                                      |
| U2   | Native sign-in arrangement, iOS splash, new tokens                                                            | Device walk                                                                                  |

## What the deeper read changed (stated in chat at plan-pause)

1. **The guard is one method, `isEmailEnabled`,** beside the existing flag checkpoints, and it sits
   **after** the Pennant flag at every site. `SendAssignmentNotifications` gets one private `queueMail`
   helper through which its six sites funnel.
2. **`DebouncedMessageMailer`: the guard sits after the flag and before the debounce row,** so a
   suppressed send never touches the debounce table. The type is derived from the mailable's `context`.
3. **Q2 reading:** `creator.approved` / `creator.rejected` **keep their existing `in_app` toggle** (deleting a
   control users already hold rows for is the wrong direction). Only the **email** cell becomes the
   "Always on" caption, and those mails stay outside the guard.
4. **Q3 scope:** no email cell for `draft_submitted` / `contracted` (their mail recipient is an agency
   inviter). `campaign_application.submitted` keeps one, because every recipient is also mailed by the
   guarded site. The guard still protects the inviter mails mechanically.
5. **Half B gating:** Q9 and Q10 say web unchanged, so U4 and U5 each carry a native branch beside the
   untouched web branch. U1 is not native-gated.

## Risk line

**LOW-MEDIUM.** The one failure mode is a guard that wrongly returns false and **silently drops mail**.
It is closed by a both-polarity test at every guarded site, a scan-based architecture test that fails on
any unclassified mail-sending file, and break-reverts in both polarities.
