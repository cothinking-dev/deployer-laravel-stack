# Secrets template for 1Password CLI
# Usage: ./deploy/dep <command> <environment>
#
# Replace "Vault/item" with your 1Password vault and item names
# Example: op://DevOps/myapp-production/password

DEPLOYER_SUDO_PASS=op://Vault/item/sudo-password
DEPLOYER_DB_PASSWORD=op://Vault/item/db-password
DEPLOYER_APP_KEY=op://Vault/item/app-key

# SSH Security: CI/CD deploy key (restricted to deploy commands only)
# Store your CI/CD public key in 1Password and reference it here.
# This key will be command-restricted - no interactive shell access.
# Admin keys (from root) get full shell access for debugging.
# DEPLOYER_DEPLOY_PUBLIC_KEY=op://Vault/item/deploy-public-key

# Optional secrets (add more as needed)
# DEPLOYER_MAIL_PASSWORD=op://Vault/item/mail-password
# DEPLOYER_AWS_SECRET=op://Vault/item/aws-secret
