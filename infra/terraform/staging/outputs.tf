output "aws_account_id" {
  description = "AWS account this state is bound to. Sanity check."
  value       = data.aws_caller_identity.current.account_id
}

output "aws_region" {
  description = "Region this environment is deployed in."
  value       = var.aws_region
}

output "available_azs" {
  description = "Availability zones available for resource placement."
  value       = data.aws_availability_zones.available.names
}
