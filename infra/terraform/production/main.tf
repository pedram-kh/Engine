/**
 * Catalyst Engine — production environment.
 *
 * Sprint 0 status: skeleton. Concrete resources (VPC, ECS, RDS Multi-AZ,
 * ElastiCache, CloudFront, S3, ALB, Route53, ACM, CloudWatch, IAM roles for
 * ECS tasks, Secrets Manager secrets) are added by Sprint 16's deploy-prep work.
 *
 * Production-specific differences vs staging (when populated):
 *   - RDS multi-AZ + automated cross-region snapshots to eu-west-1
 *   - ECS Fargate desired counts higher; auto-scaling enabled
 *   - WAF attached to CloudFront
 *   - GuardDuty + Security Hub enabled at the account level (out-of-band)
 */

data "aws_caller_identity" "current" {}

data "aws_availability_zones" "available" {
  state = "available"
}
