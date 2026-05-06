variable "aws_region" {
  description = "Primary AWS region. Default eu-central-1 (Frankfurt) per docs/00-MASTER-ARCHITECTURE.md."
  type        = string
  default     = "eu-central-1"
}

variable "environment" {
  description = "Deployment environment label. Used in tags and resource names."
  type        = string
  default     = "staging"
  validation {
    condition     = contains(["staging", "production"], var.environment)
    error_message = "environment must be 'staging' or 'production'."
  }
}

variable "owner_email" {
  description = "Team email applied to default tags. Set in staging.tfvars."
  type        = string
}

variable "cost_center" {
  description = "Finance cost center applied to default tags."
  type        = string
  default     = "engineering"
}

variable "domain" {
  description = "Apex domain for this environment (e.g., staging.catalystengine.com)."
  type        = string
}
