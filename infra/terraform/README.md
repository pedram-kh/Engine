# infra/terraform

Catalyst Engine AWS infrastructure as code. Region: **eu-central-1** (Frankfurt). Disaster-recovery region: **eu-west-1** (Ireland) — documented but not provisioned in Phase 1.

## Layout

```
terraform/
├── modules/        # Re-usable modules (vpc, ecs-service, rds-postgres, …) — added in Sprint 16+.
├── staging/        # Staging environment — terraform apply target.
└── production/     # Production environment — terraform apply target.
```

Each environment directory is independently `terraform init`-able; they share **no** state.

## Sprint 0 status

This is a **skeleton**. The Sprint 0 deliverable is the directory layout, the provider/backend stubs, and the variables manifest. Concrete resources (VPC, ECS cluster, RDS, ElastiCache, CloudFront, S3 buckets) are added by Sprint 16's "Production deploy preparation" work.

## What's required before `terraform apply`

See [`docs/SPRINT-0-MANUAL-STEPS.md`](../../docs/SPRINT-0-MANUAL-STEPS.md) Batch 2 for the complete checklist. Summary:

1. AWS Organization with separate `staging` and `production` accounts.
2. S3 bucket and DynamoDB lock table per environment, in eu-central-1, owned by the corresponding account.
3. IAM admin role assumed via SSO before running terraform.
4. `staging.tfvars` / `production.tfvars` populated with environment-specific values (kept **out of git** — see `.gitignore`).

## Conventions

- All resources tagged with `Environment`, `Project = catalyst-engine`, `ManagedBy = terraform`, `Owner` (team email), and `CostCenter`.
- Resource names prefixed with `catalyst-${var.environment}` (e.g., `catalyst-staging-rds-postgres`).
- Secrets are **never** stored in `*.tf` or `*.tfvars`. They live in AWS Secrets Manager — see [`infra/aws-secrets-manager/README.md`](../aws-secrets-manager/README.md). Terraform reads them via `data "aws_secretsmanager_secret_version"` when needed.
- Sentry DSNs and similar non-secret operational values live in SSM Parameter Store with the path layout in [`infra/sentry/README.md`](../sentry/README.md).

## Style

- Use `terraform fmt -recursive` (CI enforces).
- Use `terraform validate` per environment in CI.
- Use Terraform `>= 1.9.0` and AWS provider `>= 5.0`.
