# PL-1.1 walk script (local, for Pedram)

Branch `pl` is committed locally and **not pushed** beyond `998d0922`; run the walk from this checkout.

## Commands, in order

```bash
# 1. Local services (Postgres, Redis, MinIO) as in docs/runbooks/local-dev.md
docker compose up -d

# 2. Migrate the LOCAL database (two new tables: waves, brand_files). Local only.
cd apps/api && php artisan migrate

# 3. Seed the walk data; it prints the Bolt Food, Glovo and Zeta Foods brand ulids
php artisan db:seed --class='Database\Seeders\Pl11WalkSeeder'

# 4. Start the stack from the repo root (API + both SPAs + queue worker)
cd ../.. && pnpm dev

# ... walk steps below ...

# 5. At the end (after switching the flag OFF in the admin app)
cd apps/api && php artisan db:seed --class='Database\Seeders\Pl11WalkCleanupSeeder'
```

Sample files on your Mac for the upload steps: a small PDF, a PNG, a ZIP, one file that must be refused (`.exe` or `.svg`), and `mkfile 101m big.zip` for the size check.

**Local data.** The seeder pair `Pl11WalkSeeder` and `Pl11WalkCleanupSeeder` (`apps/api/database/seeders/`) is built: guarded to local and testing, idempotent, additive, every account under `@pl11-walk.local` with the shared local password `password-12chars` (accounts: `admin@`, `manager@`, `staff@`, and `admin-b@pl11-walk.local`). It never touches the flag, so the flag stays **OFF** until you turn it on in step 3 (plan correction C5). It prints the brand ulids it creates.

- **Agency A "PL-1.1 Walk Agency A"**, with an admin, a manager and a staff user (one shared local password constant, as in the AH-120 seeder).
  - Brands: **Bolt Food** (active), **Glovo** (active), **Old Co** (archived).
  - Campaigns: **Bolt Autumn Reels** (Bolt Food, draft, no wave), **Bolt Winter Launch** (Bolt Food, active, no wave), **Bolt Old Promo** (Bolt Food, **cancelled**, no wave), **Bolt Summer** (Bolt Food, completed, linked to Wave 5), **Glovo Spring** (Glovo, no wave).
  - Waves: **Wave 5** (Bolt Food, SK, go-live 8 Aug 2026, linked to Bolt Summer), **Wave 6** (Bolt Food, SK RO CZ, go-live 5 Sep 2026, no campaign).
- **Agency B "PL-1.1 Walk Agency B"**, one admin, brand **Zeta Foods**, campaign **Zeta Q4** (no wave), wave **Zeta Wave 1**.
- **Sample files on your Mac:** a small PDF, a PNG, a ZIP, and one file that must be refused (an `.exe` or an `.svg`). For the over-size check, a file just over 100 MB (for example made with `mkfile 101m big.zip`).
- **Services:** the local stack as in `docs/runbooks/local-dev.md` (`docker compose up` for the local services, MinIO included; `pnpm dev` from the repo root). Downloads need MinIO, because a non-S3 disk returns no signed URL (inventory §3). Your local platform-admin account turns the flag on (credentials are in the local seeders; not printed here).

**Steps**

1. **Seed.** Run `php artisan db:seed --class='Database\Seeders\Pl11WalkSeeder'`; note the two Bolt Food and Zeta Foods ulids it prints.
2. **Flag OFF, nothing changed.** Sign in to the agency app as the Agency A **admin**. Open Brands, then Bolt Food. You see the single details card, Edit and Archive, and **no** Client files and **no** Waves. Open Campaigns, Files, the sidebar: as before. In the API (browser dev tools, Network), `GET …/brands/<bolt>/waves` returns 404 `not_found`.
3. **Turn the flag on.** Sign in to the admin app as platform admin, Feature flags, find "Waves and client files", switch on with a reason. Back in the agency app, reload.
4. **Brand page, flag ON.** Open Bolt Food. Left: the same details card. Right: **Client files** (empty, with Upload files and the drop zone) and **Waves** with Wave 6 and Wave 5 (name, go-live, market codes, six empty segments and "Not started", a Draft pill, an updated date). Click Wave 6: the wave page shows go-live, markets SK RO CZ, "No campaign linked". Go back with the arrow. Open Old Co (archived): the panels show, read-only, no Upload and no New wave.
5. **Files, upload.** Upload the PDF with type Brief, then the PNG as Guidelines, then the ZIP as Assets. Each appears in the table with name, size, type chip, your name and date. Try the `.exe` or `.svg`: refused before any upload. Try the 101 MB file: refused with a size message.
6. **Files, download.** Use the row menu, Download, on the PDF: it downloads (it does not open inline).
7. **Files, delete.** Delete the PNG: confirm; it leaves the table. Reload: still gone.
8. **Wave without a campaign.** Click New wave. Name "Wave 7", a go-live date, markets Slovakia and Romania (add, remove one, add again). Leave the campaign on "No campaign". Create. Wave 7 appears first in the table with Draft. Open it: "No campaign linked".
9. **Wave with a campaign, and the dropdown.** Click New wave. Open the campaign list: it offers **Bolt Autumn Reels** and **Bolt Winter Launch** with their statuses, and **not** Bolt Summer (already linked), **not** Bolt Old Promo (cancelled), **not** Glovo Spring (another brand), **not** Zeta Q4 (another agency). Pick Bolt Autumn Reels, name it "Wave 8", create. You stay on the Brand page and Wave 8 is at the top. Open Wave 8: the campaign shows with a link; the link opens the campaign. Open New wave again: **Bolt Autumn Reels is gone** from the list; Bolt Winter Launch is still there.
   9b. **Edit a wave (sub-step E).** Open Wave 7 (no campaign), choose Edit: change the name to "Wave 7b", the go-live date and the markets; save; the table and the wave page show the change. In the same dialog link **Bolt Winter Launch**; save; it shows on the wave page and leaves the New wave list. Edit Wave 7b again: the campaign field is read-only now, and the name, date and markets still save. Edit Wave 8 (linked): the campaign cannot be changed or cleared. (Through dev tools: a PATCH of Wave 8 with `campaign_id` of another campaign, or null, returns 422 `wave.campaign_link_locked`; a PATCH of Wave 6 linking **Bolt Old Promo** returns 422 `wave.campaign_not_available`.)
10. **Roles.** Sign out, sign in as the Agency A **staff** user. Bolt Food shows the panels and the waves and files (download works), but there is no New wave, no Upload files and no Delete.
11. **Another brand's campaign never offered.** As admin on **Glovo** (a brand with no waves): New wave offers only Glovo Spring, not any Bolt campaign.
12. **A second agency cannot see anything.** Sign out, sign in as the **Agency B** admin. Brands shows only Zeta Foods. On Zeta Foods upload one file and create "Zeta Wave 2" linking Zeta Q4. Now paste Agency A's Bolt Food address (brand ulid from step 1) into the browser: the page shows not found, no waves, no files. In dev tools, `GET /api/v1/agencies/<A>/brands/<bolt>/waves` returns 404.
13. **Flag OFF again.** In the admin app switch the flag off. Reload as Agency A admin: Bolt Food is back to the single card; the wave page address redirects to the brand; the endpoints return 404; the files and waves you made are untouched in the database (they reappear when the flag is on).
14. **Clean up.** `php artisan db:seed --class='Database\Seeders\Pl11WalkCleanupSeeder'` removes the accounts, brands, campaigns, waves and file rows it made. It does **not** touch the flag: if you have not already switched it off (step 13), do it now in the admin app. Objects you uploaded to MinIO stay in the local bucket; the walk uses a local MinIO, so they can be left.
