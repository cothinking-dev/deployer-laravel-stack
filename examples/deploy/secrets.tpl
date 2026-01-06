# Secrets template for 1Password CLI
# Usage: ./deploy/dep <command> <environment>
#
# Replace "Vault/item" with your 1Password vault and item names
# Example: op://DevOps/myapp-production/password

DEPLOYER_SUDO_PASS=op://Vault/item/sudo-password
DEPLOYER_DB_PASSWORD=op://Vault/item/db-password
DEPLOYER_APP_KEY=op://Vault/item/app-key

# Optional secrets (add more as needed)
# DEPLOYER_MAIL_PASSWORD=op://Vault/item/mail-password
# DEPLOYER_AWS_SECRET=op://Vault/item/aws-secret
