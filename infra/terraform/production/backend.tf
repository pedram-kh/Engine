terraform {
  required_version = ">= 1.9.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = ">= 5.0"
    }
  }

  # State lives in the production account in eu-central-1.
  # Bucket and lock table are created out-of-band — see
  # docs/SPRINT-0-MANUAL-STEPS.md Batch 2.
  backend "s3" {
    bucket         = "catalyst-production-terraform-state"
    key            = "catalyst-engine/production/terraform.tfstate"
    region         = "eu-central-1"
    dynamodb_table = "catalyst-production-terraform-locks"
    encrypt        = true
  }
}
