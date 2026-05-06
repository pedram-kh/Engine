/**
 * Catalyst Engine — staging environment.
 *
 * Sprint 0 status: skeleton. Concrete resources (VPC, ECS, RDS, ElastiCache,
 * CloudFront, S3, ALB, Route53, ACM, CloudWatch, IAM roles for ECS tasks,
 * Secrets Manager secrets) are added by Sprint 16's deploy-prep work.
 *
 * Module structure when populated:
 *   module "vpc"             { source = "../modules/vpc"             ... }
 *   module "rds_postgres"    { source = "../modules/rds-postgres"    ... }
 *   module "elasticache"     { source = "../modules/elasticache"     ... }
 *   module "ecs_cluster"     { source = "../modules/ecs-cluster"     ... }
 *   module "ecs_api"         { source = "../modules/ecs-service"     ... }
 *   module "ecs_queue_worker"{ source = "../modules/ecs-service"     ... }
 *   module "spa_main"        { source = "../modules/cloudfront-spa"  ... }
 *   module "spa_admin"       { source = "../modules/cloudfront-spa"  ... }
 */

# Caller identity for downstream modules and outputs.
data "aws_caller_identity" "current" {}

data "aws_availability_zones" "available" {
  state = "available"
}
