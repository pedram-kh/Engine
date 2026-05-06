provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "catalyst-engine"
      Environment = var.environment
      ManagedBy   = "terraform"
      Owner       = var.owner_email
      CostCenter  = var.cost_center
    }
  }
}
