## Catalyst Creators app

This section applies when you use the Catalyst Creators app for iPhone (shown as “Catalyst” on your phone). The rest of this policy continues to apply.

### Who is responsible

Catalyst Performance Ltd, 151 Walworth Road, London SE17 1RS, United Kingdom, is the data controller. Contact: support@catalyst-growth.com.

### What we collect

- **Account:** name, email address, password (stored in hashed form), two-factor authentication settings, email verification status.
- **Profile:** profile photo, short bio, languages, accent, content categories, content companions, videos, date of birth, phone or WhatsApp number, postal address, social-media account names and follower counts, portfolio items and availability. You provide these yourself.
- **Work:** your agency relationships, campaign applications, assignments, contracts, and the messages and attachments you exchange with agencies and brands.
- **Edited and deleted messages:** when you edit a message within 15 minutes, or delete one, we keep the earlier text (and the text of a deleted message) so the agency's record stays complete. It is not shown in the app and is not visible to Catalyst support staff. It is removed if you delete your account. _Notice text for Pedram to review; not legally reviewed._
- **Device and notifications:** a push-notification token for your device, your notification preferences, whether a notification was delivered, the app version, operating system and device language.
- **Technical and security:** IP address and app or browser identifier in security logs (sign-ins, failed sign-ins and changes to your account).
- **Error reports:** when the app or our service hits an error, a report is sent to our error-monitoring provider. It contains the technical error message (with personal details such as email addresses removed), the place in the code where it happened, your device model, operating system and app version, the device's language and region setting, and, on a crash, a device identifier that is a hash. It also holds an account reference (a pseudonymous account id, with no name, email address or IP address) and whether the account is a creator. It does not contain your name, email address, IP address, location, time zone, cookies or sign-in tokens. We do not use it for session tracking or session replay.

We do not collect precise location, contacts, health data or advertising identifiers. The app does not track you across other companies’ apps or websites, and we do not sell personal data.

### Why we use it and the legal basis

- To provide the service: your account, your work with agencies, messaging and notifications (performance of a contract).
- To keep the service secure: sign-in protection, fraud prevention and security logs (legitimate interest).
- To meet legal duties where they apply (legal obligation).
- Push notifications are sent only with your permission. You can withdraw it at any time in the app’s notification settings or in iOS Settings (consent).

### Who processes it for us

- Amazon Web Services: hosting and file storage, in Ireland (EU).
- Google Firebase Cloud Messaging and Apple Push Notification service: delivery of push notifications. They receive your device token and the notification text.
- Postmark: transactional email.
- Sentry (Functional Software, Inc.): error reports, stored in the EU region.

Each provider acts under a data-processing agreement. Where a provider processes data outside the UK or EU, transfers rely on the UK International Data Transfer Agreement, the EU Standard Contractual Clauses or an adequacy decision.

### How long we keep it

- Account and profile data: while your account is active.
- Backups: up to 7 days after erasure.
- Server logs: up to 14 days.
- Error reports: up to 90 days.
- Security logs: retained, with personal details removed when your account is erased.
- Records we are legally required to keep: for the legally required period.

### Deleting your account

You can request deletion in the app (More → Security → Delete my account). Instructions are also at https://platform.catalyst-growth.com/delete-account. Your account is locked immediately and hidden from new activity. You have 14 days to change your mind by signing in again. After that period, we erase your personal data within one month: your details are removed and replaced with “[erased]” in the records agencies you worked with keep for their campaigns and contracts. Backups are overwritten within 7 days.

### Your rights

You can access, correct or delete your data, ask for a copy of it, object to or restrict processing, and withdraw consent, through the app’s settings or by emailing support@catalyst-growth.com. You can also complain to the UK Information Commissioner’s Office (ico.org.uk) or to the data-protection authority in your country.

### Age

The Catalyst Creators app is intended for people aged 18 or over.

### Changes

We will post changes to this section here and, for significant changes, notify you in the app.

Last updated: [date of publication]

---

_Everything below this line is notes for Pedram, not notice text._

## Notes on the AH-121 additions (2026-11-15)

**Engineering view, not legal advice.** Text for Pedram to review; not legally reviewed.

- **What changed:** three edits above: the **Error reports** bullet (under "What we collect"), the **Sentry** line (under "Who processes it for us"), the **Error reports: up to 90 days** line (under "How long we keep it"). `Last updated` stays a placeholder until publication.
- **Retention: "up to 90 days" (Pedram's instruction, 2026-11-15; replaces the pricing page's 30 days).** It is an upper bound, so it stays true if the org setting is shorter. Whether the org setting itself was read is **not stated to the assistant**; the 14-day trial has no paid plan chosen, and what the plan keeps after the trial is not read. Keep the wording "up to" unless the setting is read and fixed.
- **The legal basis line.** "Why we use it" already lists keeping the service secure (legitimate interest); error reports serve that and the working of the service. Whether that wording is enough for diagnostics is for counsel.
- **The erased-user case (engineering view, not legal advice).** At erasure the account row stays with its ulid and every identifying column is erased (`account-erasure.md` §7). After that the ulid in an error report identifies nobody we can name. Nothing is built to delete events at erasure (plan B6); the residue is error text that slipped past the scrubber, bounded by the retention above. Counsel decides whether that needs more.
- **"A device identifier that is a hash"** is on native crashes only (`app.device`, `device_app_hash`). What it is derived from: **not read.**
- **Time zone and language (evidence, 2026-11-15):** Claude searched both native exports (`5878a46c…`, `92a8a649…`): **no `timezone` field**; `contexts.device.locale` **is present** (`en_US@rg=eszzzz`: language plus region). So "no time zone" is claimed for the phone **on that evidence** (two events, one build), and "the device's language and region setting" is listed as held. The web events carry neither (`CultureContext` removed, D7).

## Text block for the public web privacy notice (`${SITE}/legal/privacy`)

**sent to Jordan: not yet; publication: not read.**
Blocks App Store submission of build 6; does **not** block the API and web DSNs (sentry-plan.md B9). The public notice is Catalyst's, not this repo's. **Engineering view, not legal advice; not legally reviewed.** Paste-ready, English:

> ### Error reports
>
> When something goes wrong in the Catalyst service (the website, the admin site, our servers or the Catalyst Creators app), we receive an automatic error report so we can find and fix the problem. We use Sentry (Functional Software, Inc.) for this, with data stored in the EU region.
>
> A report contains: the technical error message, with personal details such as email addresses and sign-in tokens removed; the page address without anything after the question mark or hash; the place in the code where the error happened; your device or browser model, operating system and app or site version; for the Catalyst Creators app, the device's language and region setting; and, for crashes in that app, a device identifier that is a hash. When you are signed in it also contains an account reference (a pseudonymous account id, from which we can find your account but which contains no name, email address or IP address) and whether the account is a creator, an agency or an administrator.
>
> A report does not contain your name, email address, IP address, location, time zone, cookies, sign-in tokens or the content of your messages and files. We do not record your sessions, we do not replay what you do on screen, and we do not use error reports for advertising or tracking.
>
> We use error reports to keep the service working and secure (legitimate interest). We keep them for up to 90 days. When your account is erased, your personal details are removed from our records; error reports held for the remaining days refer only to the account reference.
>
> Sentry acts for us under a data-processing agreement. Where data is processed outside the UK or EU, transfers rely on the UK International Data Transfer Agreement, the EU Standard Contractual Clauses or an adequacy decision.

**Check before sending:** (1) the "up to 90 days" (see above); (2) the last sentence of the fourth paragraph (the erased-account one) was written for erased accounts: counsel's call; (3) the transfer sentence copies the app section's wording; whether Sentry's DPA names the same mechanisms was **not read**.
