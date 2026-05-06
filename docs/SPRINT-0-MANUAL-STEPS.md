# Sprint 0 — Manual steps checklist

This is the work that cannot be automated from inside the repo: vendor application submissions, AWS account provisioning, Sentry org setup, DNS, TLS, e-sign / KYC sandboxes, and the production-readiness chores that gate later sprints.

The checklist is organized in three batches by **execution order**:

- **[Batch 1 — Submit-now](#batch-1--submit-now)** — long-lead applications. Do these this week, regardless of build pace; some take 5–14 days for vendor approval.
- **[Batch 2 — Pre-Sprint-3](#batch-2--pre-sprint-3)** — vendor sandboxes and AWS / Sentry / email / DNS prep that must be in place before integration testing the creator onboarding wizard.
- **[Batch 3 — Pre-Sprint-10](#batch-3--pre-sprint-10)** — items that gate payments integration testing.

For each step:

- Exact commands you run on your laptop.
- Direct links to the vendor pages.
- What to copy (key names, IDs, URLs).
- Where to store credentials (AWS Secrets Manager secret path).

> Every secret name follows: `catalyst/${env}/${component}/${service-or-vendor}` — see [`infra/aws-secrets-manager/README.md`](../infra/aws-secrets-manager/README.md). `${env}` is `staging` or `production`. Most vendors give you separate keys per environment; create the secret twice (one per env) when that is the case.

---

## Batch 1 — Submit now

You can run all of Batch 1 in parallel. None depends on AWS being set up. Goal: get the long-lead vendor reviews moving while we build.

### 1.1 Stripe Connect application

Vendor link: [https://dashboard.stripe.com/register](https://dashboard.stripe.com/register) → after sign-up, [https://dashboard.stripe.com/connect/overview](https://dashboard.stripe.com/connect/overview)

Steps:

1. Sign up for a Stripe account using a company email (not personal). Use the agency's legal entity name.
2. Complete identity verification on the platform account (passport / corporate registration).
3. Open the **Connect** section in the left nav. Click **Get started**.
4. Choose **Platform or marketplace**. Pick **Express** as the connected-account type.
5. Fill the platform profile: legal name, support email, country of operation (Portugal, given the agency's domicile), industry (`Marketing services`).
6. Submit the Connect application. Stripe usually responds within 3–7 business days.
7. While waiting, capture the **test mode** keys for development.

Test-mode credentials to copy now (Developers → API keys):

- Publishable key (`pk_test_...`)
- Secret key (`sk_test_...`)
- Connect client ID (Connect → Settings → "Client ID for OAuth flow")

Where to store:

```bash
aws secretsmanager create-secret \
  --region eu-central-1 \
  --name catalyst/staging/api/stripe \
  --secret-string '{"secret_key":"sk_test_...","connect_client_id":"ca_...","publishable_key":"pk_test_...","webhook_secret":""}'
```

(`webhook_secret` is filled in Batch 3.) Repeat with `--name catalyst/production/api/stripe` when production keys arrive — verification approval unlocks the live keys.

### 1.2 Meta Business app + Graph API review (Instagram OAuth)

Vendor link: [https://developers.facebook.com/apps/](https://developers.facebook.com/apps/)

Steps:

1. Create a Meta Business account at [https://business.facebook.com/](https://business.facebook.com/) tied to the agency's legal entity. Verify the business (DUNS or registration documents). This verification can take 5–14 days; do it first.
2. In the [Developer dashboard](https://developers.facebook.com/apps/), click **Create app** → **Type: Business** → name `Catalyst Engine`.
3. Add **Facebook Login for Business** product. Configure:
   - Valid OAuth redirect URIs: `https://staging.catalystengine.example/api/v1/oauth/meta/callback` and the production equivalent.
4. Add **Instagram Graph API** product. The app starts in _Development_ mode.
5. Submit for **App Review** with permissions: `instagram_basic`, `instagram_manage_insights`, `pages_show_list`, `pages_read_engagement`, `business_management`. Provide the screencast Meta requires (you'll record this against the Sprint 4 onboarding flow once it's built — placeholder for now).

Copy after creation (App Settings → Basic):

- App ID
- App Secret (regenerate; copy carefully)

Where to store:

```bash
aws secretsmanager create-secret \
  --region eu-central-1 \
  --name catalyst/staging/api/oauth/meta \
  --secret-string '{"app_id":"<appId>","app_secret":"<appSecret>"}'
```

Repeat for production.

### 1.3 TikTok for Developers app

Vendor link: [https://developers.tiktok.com/apps](https://developers.tiktok.com/apps)

Steps:

1. Sign in at [https://developers.tiktok.com](https://developers.tiktok.com) with the agency's TikTok Business account.
2. Click **Manage apps** → **Connect an app** → name `Catalyst Engine`.
3. Add product: **Login Kit**. Add redirect URLs:
   - `https://staging.catalystengine.example/api/v1/oauth/tiktok/callback`
   - `https://catalystengine.example/api/v1/oauth/tiktok/callback`
4. Request scopes: `user.info.basic`, `user.info.profile`, `user.info.stats`, `video.list`. Submit for review (3–10 business days).

Copy after creation (App detail page):

- Client key
- Client secret

Where to store:

```bash
aws secretsmanager create-secret \
  --region eu-central-1 \
  --name catalyst/staging/api/oauth/tiktok \
  --secret-string '{"client_key":"<clientKey>","client_secret":"<clientSecret>"}'
```

Repeat for production.

### 1.4 YouTube Data API v3 (Google Cloud)

Vendor link: [https://console.cloud.google.com/](https://console.cloud.google.com/)

Steps:

1. Create a Google Cloud project named `catalyst-engine-prod`.
2. Enable **YouTube Data API v3** at [https://console.cloud.google.com/apis/library/youtube.googleapis.com](https://console.cloud.google.com/apis/library/youtube.googleapis.com).
3. Configure the **OAuth consent screen** (External, Production):
   - App name: `Catalyst Engine`
   - User support email: agency support address
   - Authorized domains: `catalystengine.example`
   - Scopes: `https://www.googleapis.com/auth/youtube.readonly`, `https://www.googleapis.com/auth/yt-analytics.readonly`
4. Submit for verification. Add `youtube.readonly` scope justification — typically 5–10 business days for a non-restricted scope.
5. Create an **OAuth 2.0 Client ID** (Web application) with redirect URIs for staging and production.

Copy:

- Client ID
- Client secret

Where to store:

```bash
aws secretsmanager create-secret \
  --region eu-central-1 \
  --name catalyst/staging/api/oauth/google \
  --secret-string '{"client_id":"<clientId>","client_secret":"<clientSecret>"}'
```

Repeat for production.

### 1.5 Domain registration

Vendor link: pick one of [Cloudflare Registrar](https://www.cloudflare.com/products/registrar/), [AWS Route 53](https://console.aws.amazon.com/route53/v2/registrar), [Namecheap](https://www.namecheap.com/).

Steps:

1. Register the production domain (e.g., `catalystengine.com`). Use the agency's company contact for WHOIS, with privacy enabled.
2. Decide on the staging hostname now: `staging.catalystengine.com` is the convention this repo assumes.
3. **Do not** point DNS records yet — we add A/AAAA/CNAME during Batch 2 once AWS is provisioned.

What to capture:

- Registrar name (for the runbook)
- Domain expiration date (set a 60-day-before reminder)

No secret stored — domain registration is a single fact, not a credential.

### 1.6 Optional: GitHub repository

If you intend to use GitHub for hosting the repo (the CI workflows in [`.github/workflows/`](../.github/workflows/) target it), do this now while waiting on the other reviews.

Steps:

1. Create a new private repository at [https://github.com/new](https://github.com/new) named `catalyst-engine` under the company org.
2. Push the local repo:
   ```bash
   cd /Users/pedram/Desktop/[PROJECT]/Catalyst-Engine
   git remote add origin git@github.com:<org>/catalyst-engine.git
   git push -u origin main
   ```
3. In repo Settings → Secrets and variables → Actions, add (later, once AWS is set up):
   - `AWS_ACCESS_KEY_ID` (CI deploy role — out of scope until Sprint 16)
   - `AWS_SECRET_ACCESS_KEY`
   - `SENTRY_AUTH_TOKEN` (for source-map uploads in Sprint 16)

---

## Batch 2 — Pre-Sprint-3

These must be in place **before integration testing the creator onboarding wizard** (Sprints 3–6 author the wizard; the user runs through it end-to-end during Sprint 6 acceptance). Plan to finish Batch 2 in the week leading into Sprint 3.

### 2.1 AWS Organization + accounts

Vendor link: [https://console.aws.amazon.com/organizations/](https://console.aws.amazon.com/organizations/)

Steps:

1. From your existing AWS account (or a fresh management account), enable AWS Organizations.
2. Create two member accounts under the org:
   - `catalyst-staging` — billing alias `catalyst-staging`, root contact = agency CTO.
   - `catalyst-production` — billing alias `catalyst-production`, root contact = agency CTO.
3. Set IAM Identity Center (SSO) up at the org level. Create permission sets:
   - `CatalystAdmin` (AdministratorAccess) — assigned to engineering staff in both staging and production.
   - `CatalystReadOnly` (ReadOnlyAccess) — for support / on-call read-only investigations.
4. Sign in to each account at least once via SSO to verify access.

Capture in your runbook (no secret to store):

- AWS Org ID
- Staging account ID
- Production account ID

### 2.2 Terraform state buckets + lock tables

Run these commands once per environment, **logged into the corresponding account**.

#### Staging account

```bash
# 1. Create the state bucket (versioning + encryption + public-access-blocked).
aws s3api create-bucket \
  --region eu-central-1 \
  --bucket catalyst-staging-terraform-state \
  --create-bucket-configuration LocationConstraint=eu-central-1

aws s3api put-bucket-versioning \
  --bucket catalyst-staging-terraform-state \
  --versioning-configuration Status=Enabled

aws s3api put-bucket-encryption \
  --bucket catalyst-staging-terraform-state \
  --server-side-encryption-configuration '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"AES256"}}]}'

aws s3api put-public-access-block \
  --bucket catalyst-staging-terraform-state \
  --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true

# 2. Create the DynamoDB lock table.
aws dynamodb create-table \
  --region eu-central-1 \
  --table-name catalyst-staging-terraform-locks \
  --attribute-definitions AttributeName=LockID,AttributeType=S \
  --key-schema AttributeName=LockID,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST
```

#### Production account

Run the same block in the production account, swapping `staging` for `production` everywhere.

These names are referenced verbatim by `infra/terraform/{staging,production}/backend.tf`.

### 2.3 First Terraform apply

```bash
cd infra/terraform/staging

# Copy the example tfvars and fill in real values.
cp example.tfvars staging.tfvars
$EDITOR staging.tfvars

# Initialize the backend (uses S3 + DynamoDB created in 2.2).
terraform init

# Plan and apply.
terraform plan  -var-file=staging.tfvars -out=plan.tfplan
terraform apply plan.tfplan
```

Sprint 0's terraform skeleton creates **no resources** — apply will succeed with "No changes." The point of running it now is to validate that the backend, IAM SSO role, and provider chain are correctly configured before Sprint 16 tries to provision actual infrastructure.

Repeat in `infra/terraform/production/` with `production.tfvars`.

### 2.4 Sentry organization + projects

Vendor link: [https://sentry.io/signup/](https://sentry.io/signup/) (choose EU data residency).

Steps:

1. Create org slug `catalyst-engine` on the EU region (`https://catalyst-engine.sentry.io`).
2. Create three projects per [`infra/sentry/README.md`](../infra/sentry/README.md):
   - `catalyst-api` (platform: PHP / Laravel)
   - `catalyst-main` (platform: JavaScript / Vue)
   - `catalyst-admin` (platform: JavaScript / Vue)
3. For each project, copy its DSN. Store in Secrets Manager for both environments:

```bash
# API
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/staging/api/sentry --secret-string '{"dsn":"<staging-api-dsn>"}'
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/production/api/sentry --secret-string '{"dsn":"<production-api-dsn>"}'

# Main SPA
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/staging/spa-main/sentry --secret-string '{"dsn":"<staging-main-dsn>"}'
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/production/spa-main/sentry --secret-string '{"dsn":"<production-main-dsn>"}'

# Admin SPA
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/staging/spa-admin/sentry --secret-string '{"dsn":"<staging-admin-dsn>"}'
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/production/spa-admin/sentry --secret-string '{"dsn":"<production-admin-dsn>"}'
```

4. Configure Sentry environments per project: `staging`, `production` (Sentry creates them automatically on first event).
5. Wire Slack alert routing per [`infra/sentry/README.md`](../infra/sentry/README.md).

### 2.5 DNS records

Once Terraform provisions the staging CloudFront + ALB (Sprint 16), point DNS at them. Until then, set up the **zone** at the registrar / Route 53:

```bash
# In Route 53 (production account)
aws route53 create-hosted-zone \
  --name catalystengine.example \
  --caller-reference "$(date +%s)"
```

Capture the four nameservers and update them at the registrar.

For staging, add a delegation NS record under the production zone pointing at a staging-only hosted zone in the staging account:

```bash
# In Route 53 (staging account)
aws route53 create-hosted-zone \
  --name staging.catalystengine.example \
  --caller-reference "$(date +%s)"
```

Then add the staging NS records as a delegation in the production zone.

### 2.6 TLS certificates

ACM is regional. Provision two certificates **in `us-east-1`** (CloudFront requires this) plus one **in `eu-central-1`** (for the ALB). Domain validation is via DNS — use the Route 53 zone from 2.5.

```bash
# CloudFront cert (us-east-1) — wildcard for staging
aws acm request-certificate --region us-east-1 \
  --domain-name "*.staging.catalystengine.example" \
  --subject-alternative-names "staging.catalystengine.example" \
  --validation-method DNS

# CloudFront cert (us-east-1) — wildcard for production
aws acm request-certificate --region us-east-1 \
  --domain-name "*.catalystengine.example" \
  --subject-alternative-names "catalystengine.example" \
  --validation-method DNS

# ALB cert (eu-central-1) — staging
aws acm request-certificate --region eu-central-1 \
  --domain-name "api.staging.catalystengine.example" \
  --validation-method DNS

# ALB cert (eu-central-1) — production
aws acm request-certificate --region eu-central-1 \
  --domain-name "api.catalystengine.example" \
  --validation-method DNS
```

For each certificate, the AWS console will show CNAME validation records to add to Route 53 (one click in the console, or `aws acm describe-certificate` and `aws route53 change-resource-record-sets`).

No secret stored — certificate ARNs are state, surfaced via Terraform outputs once Sprint 16 wires them up.

### 2.7 Email provider

Pick one of: AWS SES, Postmark, or Mailgun.

Recommendation: **AWS SES** (eu-central-1) for cost and locality. If you prefer fewer reputation worries early on, Postmark.

#### AWS SES (eu-central-1)

```bash
# Verify the apex domain (production account, eu-central-1).
aws ses verify-domain-identity --region eu-central-1 \
  --domain catalystengine.example

# Returns a TXT verification token — add to Route 53 zone.
# Then enable DKIM:
aws ses verify-domain-dkim --region eu-central-1 \
  --domain catalystengine.example
# Returns three CNAME tokens — add all three to Route 53.

# Request production access (out of sandbox):
# https://console.aws.amazon.com/ses/home?region=eu-central-1#account-dashboard
```

Repeat for `staging.catalystengine.example`.

Add an SPF record: `TXT @ "v=spf1 include:amazonses.com -all"`. Add DMARC: `TXT _dmarc "v=DMARC1; p=quarantine; rua=mailto:dmarc@catalystengine.example"`.

Where to store:

```bash
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/staging/api/email \
  --secret-string '{"provider":"ses","region":"eu-central-1","from_address":"no-reply@staging.catalystengine.example","from_name":"Catalyst Engine"}'
```

Repeat for production.

#### Postmark / Mailgun

If using one of these, store the corresponding API token:

```bash
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/staging/api/email \
  --secret-string '{"provider":"postmark","server_token":"<token>","from_address":"...","from_name":"Catalyst Engine"}'
```

### 2.8 KYC vendor sandbox

Phase 1 uses a KYC provider (final choice TBD). Recommended candidates: **Onfido**, **Persona**, **Veriff**.

Vendor links:

- Onfido: [https://onfido.com/signup/](https://onfido.com/signup/)
- Persona: [https://withpersona.com/get-started](https://withpersona.com/get-started)
- Veriff: [https://www.veriff.com/integrations/sandbox](https://www.veriff.com/integrations/sandbox)

Steps (vendor-agnostic):

1. Create a sandbox account.
2. Capture API key (sandbox), webhook signing secret, dashboard URL.
3. Configure webhook URL: `https://api.staging.catalystengine.example/api/v1/integrations/kyc/webhook`.

Where to store:

```bash
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/staging/api/kyc \
  --secret-string '{"provider":"onfido","api_key":"<sandbox-api-key>","webhook_secret":"<webhook-signing-secret>","region":"eu"}'
```

Repeat with production sandbox keys (most KYC vendors give you a separate "production" key once you've signed the contract — usually 30–60 days lead time, do this during Batch 3 readiness).

### 2.9 E-sign vendor sandbox

Recommended candidates: **DocuSign**, **HelloSign (Dropbox Sign)**, **Yousign** (EU-friendly).

Vendor links:

- DocuSign sandbox: [https://account-d.docusign.com/](https://account-d.docusign.com/)
- Dropbox Sign (HelloSign) sandbox: [https://app.hellosign.com/api/](https://app.hellosign.com/api/)
- Yousign sandbox: [https://yousign.com/developers](https://yousign.com/developers)

Steps:

1. Create developer / sandbox account.
2. Generate API key (sandbox).
3. Capture account ID and webhook signing secret.
4. Configure webhook URL: `https://api.staging.catalystengine.example/api/v1/integrations/esign/webhook`.

Where to store:

```bash
aws secretsmanager create-secret --region eu-central-1 \
  --name catalyst/staging/api/esign \
  --secret-string '{"provider":"docusign","api_key":"<sandbox-key>","account_id":"<account-id>","integration_key":"<integration-key>"}'
```

---

## Batch 3 — Pre-Sprint-10

These items gate **payments integration testing** (Sprints 10–11 build the campaign-payment lifecycle). Plan to finish Batch 3 in the week leading into Sprint 10.

### 3.1 Stripe Connect production approval verified

By Sprint 10, Stripe should have approved the platform application from Batch 1 §1.1. Verify:

1. Sign in to [https://dashboard.stripe.com/](https://dashboard.stripe.com/) and toggle to **Live mode**.
2. Go to Connect → Settings. Confirm:
   - Account type: **Express** (enabled)
   - Branding: agency logo + colors uploaded
   - Capabilities requested: `card_payments`, `transfers` (and `legacy_payments` only if needed)
3. Capture live keys (Developers → API keys, Live):
   - `pk_live_...`
   - `sk_live_...`
   - Connect client ID (`ca_...`) — same value as test mode

Store in production secret:

```bash
aws secretsmanager put-secret-value --region eu-central-1 \
  --secret-id catalyst/production/api/stripe \
  --secret-string '{"secret_key":"sk_live_...","connect_client_id":"ca_...","publishable_key":"pk_live_...","webhook_secret":""}'
```

(`webhook_secret` is filled in §3.2.)

### 3.2 Stripe webhook endpoints

Configure webhooks for both staging and production. Stripe supports separate webhook configs per environment via the test/live toggle.

Vendor link: [https://dashboard.stripe.com/test/webhooks/create](https://dashboard.stripe.com/test/webhooks/create) (test) and [https://dashboard.stripe.com/webhooks/create](https://dashboard.stripe.com/webhooks/create) (live).

Endpoint URLs:

- Staging: `https://api.staging.catalystengine.example/api/v1/integrations/stripe/webhook`
- Production: `https://api.catalystengine.example/api/v1/integrations/stripe/webhook`

Events to subscribe (Phase 1):

```
account.updated
account.application.deauthorized
charge.succeeded
charge.failed
charge.refunded
checkout.session.completed
checkout.session.expired
payment_intent.succeeded
payment_intent.payment_failed
payment_intent.canceled
transfer.created
transfer.failed
transfer.reversed
payout.created
payout.failed
payout.paid
```

After saving each endpoint, copy the **Signing secret** (`whsec_...`).

Update the corresponding Secrets Manager entries:

```bash
# Staging (test mode)
aws secretsmanager get-secret-value --region eu-central-1 --secret-id catalyst/staging/api/stripe --query SecretString --output text \
  | jq '.webhook_secret = "<whsec_test_value>"' \
  | xargs -I{} aws secretsmanager put-secret-value --region eu-central-1 --secret-id catalyst/staging/api/stripe --secret-string '{}'

# Production (live mode)
aws secretsmanager get-secret-value --region eu-central-1 --secret-id catalyst/production/api/stripe --query SecretString --output text \
  | jq '.webhook_secret = "<whsec_live_value>"' \
  | xargs -I{} aws secretsmanager put-secret-value --region eu-central-1 --secret-id catalyst/production/api/stripe --secret-string '{}'
```

### 3.3 E-sign production keys

By Sprint 10, you should have signed an enterprise contract with the chosen e-sign vendor. Generate production API credentials and store them:

```bash
aws secretsmanager put-secret-value --region eu-central-1 \
  --secret-id catalyst/production/api/esign \
  --secret-string '{"provider":"docusign","api_key":"<production-key>","account_id":"<production-account-id>","integration_key":"<production-integration-key>"}'
```

Configure the production webhook endpoint in the vendor dashboard:

- URL: `https://api.catalystengine.example/api/v1/integrations/esign/webhook`
- Event types: `envelope.sent`, `envelope.delivered`, `envelope.completed`, `envelope.declined`, `envelope.voided`.

### 3.4 Local webhook tunneling

To test Stripe / e-sign / KYC webhooks against the local API (`http://127.0.0.1:8000`), use a tunnel.

#### Option A — ngrok (simplest)

```bash
brew install ngrok/ngrok/ngrok
ngrok config add-authtoken <token-from-https://dashboard.ngrok.com/get-started/your-authtoken>
ngrok http 8000
```

Copy the temporary HTTPS URL it prints (e.g., `https://abcd1234.ngrok-free.app`). For each vendor webhook (Stripe, e-sign, KYC), add a temporary endpoint that points at `<ngrok-url>/api/v1/integrations/<vendor>/webhook` while developing.

#### Option B — Cloudflare Tunnel (free, persistent subdomain)

```bash
brew install cloudflare/cloudflare/cloudflared
cloudflared tunnel login
cloudflared tunnel create catalyst-dev
cloudflared tunnel route dns catalyst-dev dev.<your-cloudflare-zone>
cloudflared tunnel run --url http://127.0.0.1:8000 catalyst-dev
```

This gives you a stable URL like `https://dev.<your-zone>` you can hardcode in the dev-mode vendor webhook configs.

#### Option C — Stripe CLI listener (Stripe-only)

For Stripe specifically, the easiest local-webhook flow is the Stripe CLI:

```bash
brew install stripe/stripe-cli/stripe
stripe login
stripe listen --forward-to http://127.0.0.1:8000/api/v1/integrations/stripe/webhook
```

The CLI prints a local-only webhook signing secret on startup. Use that secret in your **local** `.env` (`STRIPE_WEBHOOK_SECRET=whsec_...`) — it does not touch the staging / production secrets.

---

## When Sprint 0 closes

Sprint 0 is **code-complete** when this repo's scaffolding (apps, packages, infra, CI), [`docs/feature-flags.md`](feature-flags.md), and this checklist are all in place. That is achievable without leaving your editor.

Sprint 0 **closes** when **Batch 1** above is submitted — you don't have to wait for vendor approvals to come back, just for the applications to be filed. Once Batch 1 is submitted, we move into Sprint 1 (Identity & Authentication).

Batch 2 must be done before Sprint 3 acceptance. Batch 3 must be done before Sprint 10 acceptance. Both are tracked as gating items in the corresponding sprint's definition of done.
