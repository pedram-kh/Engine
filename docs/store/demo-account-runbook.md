# Demo account for App Review: runbook (AH-105)

**⚠ PROD-DATA RISK: LOW to MEDIUM when performed.** This runbook **writes production rows**: a creator, an agency, a brand, a campaign, an invitation, a roster relation, a legal hold. Every step below says whether it writes and how to read it back. Nothing here is run by the engineering side; Pedram performs it, after the deploy, with `account_deletion_enabled` ON. The same fiction as `StoreScreenshotSeeder` is the content script (invented names only), but **the seeder never runs in production** (it throws outside `local` and `testing`); production is built by hand, as below.

**What the reviewer needs** (`review-notes.md`): an **approved** creator with no two-factor prompt, one campaign invitation, one conversation with an agency, a profile and a portfolio, deletion visible. Reviewers cannot reach "approved" themselves (up to three business days, admin approval), so this account is mandatory.

**Never put the demo password in this repository, a commit, a ticket or a chat.** It lives in your password manager; ASC's sign-in fields hold it for the review.

## What the code says that shapes the steps (read, 2026-10-29)

| Fact                                                                                                                                                                                              | Source                                                                                                                | Consequence                                                                                                                                                          |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **An agency cannot be created in the admin UI.** The admin agencies group is list, show, suspend, reactivate and the member 2FA actions only. (Q8)                                                | `apps/api/app/Modules/Admin/Routes/api.php:61-84`; the `_test` helper exists only in `local` and `testing`            | Step 2 is a **tinker script** (bootstrap-only), with a read-back.                                                                                                    |
| **A direct invite needs a discoverable creator**; accepting an application drops that rule. There is **no UI that sets `is_discoverable`**.                                                       | `CampaignAssignmentController.php:157-163`; `grep is_discoverable` finds no write path                                | **Order matters:** invite first, **then** make the creator undiscoverable (step 6, tinker). Never the other way round (the invite would 404).                        |
| **The admin UI cannot hold an account that has not asked to leave** (the queue lists requests only); the hold service accepts any creator.                                                        | `apps/api/app/Modules/Erasure/Hold/ErasureHold.php`; `ErasureQueuePage.vue`                                           | Step 7 is a **tinker script** for the hold, with a read-back and an audit row.                                                                                       |
| **Approving a creator is gated on identity "cleared"** (`verified` or `not_required`); the admin page has a "Verify identity" action if it is not.                                                | `apps/admin/src/modules/creators/pages/CreatorDetailPage.vue:366-421`                                                 | If **Approve** is missing on the creator page, use **Verify identity** first.                                                                                        |
| **Approval can fire platform automations** (rules that connect a newly approved creator to agencies), only while `platform_automations_enabled` is on.                                            | `AdminCreatorController.php:306`, `:466` (`dispatchApprovalAutomations`); flag listed in `apps/api/app/Core/Features` | **Check before approving** (step 0). An armed rule could connect the demo creator to a **real** agency.                                                              |
| **Impersonation can create brands, campaigns and invitations**; it blocks only password change, 2FA disable and codes, contract accept and payout release. It refuses a leaving or erased target. | `apps/api/app/Core/Impersonation/HardBlockedActions.php:29-46`; `docs/05-SECURITY-COMPLIANCE.md:248`                  | The agency steps (step 4) are done **as the agency admin through Admin → impersonate**, so the demo agency's admin never needs a real password or 2FA.               |
| **Fan-out audience is the listing agency's roster**, not all creators.                                                                                                                            | `JobPostedFanOutService.php:190-215` (`eligibleRelations`: `permitsMessaging`, approved, visible)                     | Correction to inventory §4 (see "Decision for you" below): with a **dedicated** agency whose roster is only the demo creator, listing could not reach real creators. |
| **A brand must carry the floor**: name, slug, description, industry, website, logo. The logo is set only by the logo upload endpoint.                                                             | `apps/api/app/Modules/Brands/Models/Brand.php:49-76`                                                                  | Step 4 includes uploading a logo you own.                                                                                                                            |
| **The demo creator's relation is `roster`** through the admin **Connect directly** mode, which records an offline agreement and notifies the creator.                                             | `apps/admin/src/core/i18n/locales/en/creators.json:189-201`; `AdminCreatorConnectionController.php:45-52,133`         | Step 2 uses it; it also gives the relationship conversation (a thread needs a `roster` relation).                                                                    |

## Step 0. Read-backs before you write anything (read-only)

1. Admin → Operations → **Feature flags**: `account_deletion_enabled` is **ON** (reason recorded). `platform_automations_enabled`: **if ON**, open Admin → **Automations** and read every rule that fires on creator approval. If any rule could connect a creator to an agency, **stop and decide** (turn the rule off with a reason, or approve with the flag off). Do not approve while an armed rule might attach the demo creator to a real agency.
2. Admin → Creators → **Pending approvals**: note the queue so the demo creator is easy to recognise by its mailbox.
3. A mailbox you control for the demo creator (`<DEMO_EMAIL>`) and one for the demo agency admin (`<AGENCY_DEMO_EMAIL>`). **Not** a personal address you do not want in a screenshot; an alias is ideal. Do not use a real creator's address.

## Step 1. The demo creator (writes: a user, a creator, files)

1. In the app or on the web, **sign up** with `<DEMO_EMAIL>` and a strong password (store it now). Verify the email from the mailbox.
2. Complete the wizard with **invented, adult, neutral content**: a fictional display name (the screenshots use "Alessia Moretti"), country and region, date of birth making the person an adult, primary language, at least one category, an avatar and two or three portfolio images **you own** (generated gradients or licensed images; no real face of a third party), a typed social handle (OAuth is off). The visible wizard steps are profile, social and portfolio; identity, tax and payout are hidden build-wide (`packages/api-client/src/wizard.ts:30`).
3. **Submit** the application.
4. **Read-back:** Admin → Creators → **Pending approvals** lists the creator.

## Step 2. The demo agency: bootstrap-only (writes: an agency, a user, a membership)

An agency cannot be created through the admin UI (Q8). Run this **once**, on the production server, through `php artisan tinker`, modelled on `Sprint1IdentitySeeder::seedAgencyWithAdmin` (`apps/api/database/seeders/Sprint1IdentitySeeder.php`, `seedAgencyWithAdmin`). The user's password is random and discarded: the account is **only reached by impersonation**, never by signing in.

```php
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

DB::transaction(function () {
    $agency = Agency::query()->updateOrCreate(
        ['slug' => 'app-review-demo'],
        [
            'name' => 'Catalyst Demo Agency',
            'country_code' => 'GB',
            'default_currency' => 'EUR',
            'default_language' => 'en',
            'subscription_tier' => 'pilot',
            'subscription_status' => 'active',
            'settings' => [
                'blacklist_notification_default' => false,
                'escrow_funding_moment' => 'on_contract_sign',
            ],
            'is_active' => true,
        ],
    );

    $admin = User::query()->updateOrCreate(
        ['email' => '<AGENCY_DEMO_EMAIL>'],
        [
            'name' => 'Demo Agency Admin',
            'type' => UserType::AgencyUser,
            'password' => Hash::make(Str::random(48)),
            'email_verified_at' => now(),
            'preferred_language' => 'en',
            'preferred_currency' => 'EUR',
            'timezone' => 'Europe/London',
            'mfa_required' => false,
        ],
    );

    AgencyMembership::query()->updateOrCreate(
        ['agency_id' => $agency->id, 'user_id' => $admin->id],
        ['role' => AgencyRole::AgencyAdmin, 'accepted_at' => now()],
    );
});
```

**Read-back (read-only), in the same tinker session:**

```php
$a = App\Modules\Agencies\Models\Agency::query()->where('slug', 'app-review-demo')->firstOrFail();
[$a->ulid, $a->name, $a->is_active, $a->subscription_status];
App\Modules\Agencies\Models\AgencyMembership::query()->where('agency_id', $a->id)->get(['user_id', 'role', 'accepted_at'])->toArray();
App\Modules\Agencies\Models\Agency::query()->count();
```

Expected: the agency row with `is_active = true`; exactly **one** membership (role `agency_admin`, `accepted_at` set); and the agency count equal to the number you had before **plus one**. Admin → **Agencies** should now list "Catalyst Demo Agency". If it does not, stop; do not re-run blindly (the script is idempotent by slug and email, so a second run changes nothing, but a half-written state needs reading first).

## Step 3. Approve and connect (writes: application status, a relation, mails)

1. Admin → open the creator → **Approve** (button "Approve"; dialog title "Approve {name}'s application"). If it is not offered, use **Verify identity** first (it is the manual clearance), then Approve.
2. Same page, **Connections**: **Connect to agency** (the agency from step 2 must exist). Choose **Connect directly** (records an offline agreement), give a reason of at least the minimum length (the dialog says how many characters), confirm.
3. **Read-back:** the creator page shows the connection to the demo agency; the creator's app shows the agency in Messages.

## Step 4. The brand, the campaign and the invitation (writes: a brand, a campaign, an assignment, a mail)

Do these **as the agency admin through impersonation**: Admin → Support → **User search** (find `<AGENCY_DEMO_EMAIL>`, button **Impersonate**; a reason is mandatory and logged; it opens a new tab signed in as that user). End the impersonation when done. The agency app's screen labels were **not read** for this runbook; follow the screens, and if a step differs from this text, write the difference here.

1. **Brand**: create one fictional brand carrying the whole floor (name, description, industry, website, **and a logo uploaded through the brand's logo control**): `Brand::FLOOR_FIELDS`, `apps/api/app/Modules/Brands/Models/Brand.php:49-76`. A name that is not a real company (the screenshots use "Lumora Skincare").
2. **Campaign** under that brand, made **active**, with a short brief. **Do NOT list it on the jobs board** (the listing switch stays off; see "Decision for you" below).
3. **Invite** the demo creator to the campaign (the campaign's creators area). The creator must be approved **and still discoverable** at this moment (`CampaignAssignmentController.php:157-163`).
4. **Read-back:** in the creator's app (sign in as the demo creator on a device or the web), **Invitations** shows one invitation in the "invited" state; the creator received the invitation mail at `<DEMO_EMAIL>`.
5. **Conversation:** open **Messages** as the agency admin (impersonating), choose the demo creator, send a short welcome message, and have the creator answer once from the app. This gives the reviewer a conversation to see and, with the demo, a report link to find. (Needs the roster relation from step 3.)

## Step 5. Leave the invitation unanswered

Leave the invitation **unanswered** (invited): it is what the dashboard and the Invitations tab show, and the reviewer can open it. Do not accept it: acceptance moves toward contracts, which are off.

## Step 6. Make the creator undiscoverable (writes: one column; tinker, no UI)

Only **after** step 4 (the invite). The demo creator should not appear in the discovery list of **real** agencies. There is no admin or app control for `is_discoverable`, so:

```php
use App\Modules\Creators\Models\Creator;

$c = Creator::query()->whereHas('user', fn ($q) => $q->where('email', '<DEMO_EMAIL>'))->firstOrFail();
$c->forceFill(['is_discoverable' => false])->save();
```

**Read-back:** `Creator::query()->whereHas('user', fn ($q) => $q->where('email', '<DEMO_EMAIL>'))->value('is_discoverable');` returns `false` (`0`). The invitation and the conversation keep working: both were created before the flip, and the relation does not depend on it.

## Step 7. The legal hold (writes: one column and one audit row; tinker, no UI)

So a stray **Erase** click can never touch the demo account (the Monday routine, `account-erasure.md` §1d, never erases a held account). The admin Compliance page lists only accounts that have asked to leave, so the hold is set by the service the page itself uses:

```php
use App\Modules\Erasure\Hold\ErasureHold;
use App\Modules\Identity\Models\User;

$admin = User::query()->where('email', '<YOUR_ADMIN_EMAIL>')->firstOrFail();
$demo = User::query()->where('email', '<DEMO_EMAIL>')->firstOrFail();

app(ErasureHold::class)->hold($demo, $admin, 'App Review demo account; do not erase while the app is under review.');
```

**Read-back:** `$demo->refresh()->isErasureHeld();` returns `true`; Admin → **Audit log** shows an `account.erasure_held` row for the account with your reason (audit-log viewer, `apps/api/app/Modules/Admin/Routes/api.php`, `audit-logs`). The hold does **not** stop a deletion _request_ (the reviewer can still test the Delete flow); it only stops the erasure. If a reviewer requests deletion, the account appears in the Erasure queue's **Pending** tab, labelled "On hold".

## Step 8. The reviewer's test, end to end (you, before submission)

On a device with build 3 (or the web), sign in as the demo creator and confirm, **in this order**:

1. Dashboard shows the approved banner (AH-111 wording), the strength card and **one invitation**.
2. **Invitations** shows the invited chip. **Messages** shows the conversation; **Report a problem** is visible in it (device walk: with a mail account, and on a phone **without** one).
3. **More → Security** exists (flag ON) and **Delete my account** opens the dialog. Do **not** confirm on the demo account.
4. **More → Privacy Policy** opens the policy in the browser (device walk).
5. Sign out. Sign in once more to prove the password works from a clean state.

## Cleanup after the review

- **Rotate the demo password**: the sign-in page's **Forgot password** link sends the reset mail to `<DEMO_EMAIL>` (`auth.forgot-password` route). Store the new one; ASC's sign-in fields are updated per submission.
- **Reviewer-created accounts** (they sign up for real, so they are real rows and **real approval-queue entries**): in Admin → Creators → **Pending approvals**, **Reject** each one (dialog "Reject {name}'s application", a reason). Their erasure waits for the Monday routine, which starts only after the C4 preconditions close (`account-erasure.md` §1d), so until then they sit rejected. This is expected.
- **Keep the demo account and the hold** as long as the app may be re-reviewed. Release the hold and erase it only when you retire the demo (Compliance → Erasure queue → **Release**, then the normal path).
- **Never** list the demo campaign, never connect the demo creator to a real agency, never invite the demo creator from a real agency.

## Decision for you (a correction to the inventory, not a change I made)

Inventory §4 ruled that **the demo campaign must not be listed** because listing "would fan out to about 279 real creators". Reading `JobPostedFanOutService::eligibleRelations` (`apps/api/app/Modules/Campaigns/Services/JobPostedFanOutService.php:190-215`) shows the audience is **the listing agency's own roster** (`permitsMessaging`, approved, visible). With the **dedicated** demo agency whose roster is only the demo creator, a listed demo job could reach **only the demo creator**. This runbook keeps your ruling (**unlisted**), so the demo's **Job Posts** tab is empty, which the review notes already allow ("can be empty"). If you would rather the reviewer see a post, one more campaign on the demo agency may be listed after you read the roster back as exactly one creator; say so and I will add the step and change the notes.

## Not verified

- The agency app's button labels in step 4 (not read).
- That the production deploy has `account_deletion_enabled` ON and `platform_automations_enabled` in the state step 0 expects (to read in the admin Feature flags page).
- That `php artisan tinker` is available on the production host (the deploy runbooks use it; confirm the exact command for your host).
- That the reset mail reaches `<DEMO_EMAIL>` (Postmark delivery) before you rely on it for the rotation.
