# AI Agent Setup Guide

This document provides instructions for AI agents to configure deployer-laravel-stack for a Laravel project. Follow this guide systematically to gather requirements and generate deployment configuration.

## Overview

You are configuring a zero-downtime deployment system for a Laravel application. The system uses:
- **Deployer** for deployment orchestration
- **Caddy** as the web server with automatic HTTPS
- **PostgreSQL**, **MySQL**, or **SQLite** for the database
- **Redis** for caching/queues (optional)
- **1Password CLI** or plain `.env` for secrets management

## Prerequisites Check

Before proceeding, verify:

1. **Local machine has**:
   - PHP 8.2+
   - Composer installed
   - SSH access to the target server

2. **Server has**:
   - Ubuntu 20.04+ (fresh install recommended)
   - SSH access as root (for initial bootstrap)

3. **Project has**:
   - Valid `composer.json`
   - Laravel 10+ application

## Step 1: Gather Project Information

You MUST collect the following information. If any required field is unknown, ASK THE USER.

### Required Information

```yaml
# REQUIRED - Must have values
application_name: ""          # Human-readable name (e.g., "My Application")
repository_url: ""            # Git SSH URL (e.g., "git@github.com:org/repo.git")
server_hostname: ""           # Server IP or hostname (e.g., "192.168.1.100" or "server.example.com")
production_domain: ""         # Production domain (e.g., "myapp.com")

# REQUIRED - Choose one
database_type: ""             # One of: "pgsql", "mysql", "sqlite"

# REQUIRED - Choose one
secrets_management: ""        # One of: "1password", "env"

# REQUIRED - Choose one
web_server_mode: ""           # One of: "fpm" (PHP-FPM + Caddy), "octane" (Laravel Octane + FrankenPHP)
```

### Optional Information (has sensible defaults)

```yaml
# Optional - Multi-environment
staging_enabled: true                    # Whether to set up staging environment
staging_domain: "staging.{prod_domain}"  # Staging domain

# Optional - Versions
php_version: "8.4"                       # PHP version to install
node_version: "22"                       # Node.js version for asset building

# Optional - Redis
redis_enabled: true                      # Whether to use Redis
redis_db_prod: 0                         # Redis database number for production
redis_db_staging: 1                      # Redis database number for staging

# Optional - Queue workers
queue_workers_enabled: false             # Whether to set up Supervisor queue workers

# Optional - TLS
tls_mode: "internal"                     # "internal" (self-signed, for Cloudflare) or "acme" (Let's Encrypt)

# Optional - Media/uploads
storage_links: {}                        # Map of public paths to shared paths
                                         # e.g., {"media": "media", "uploads": "uploads"}
```

### If Using 1Password

```yaml
op_vault: "DevOps"                       # 1Password vault name
op_item: "{application_slug}"            # 1Password item name (lowercase, hyphenated)
```

### If Using SQLite

```yaml
# SQLite requires additional configuration
sqlite_path: "database"                  # Directory within shared/ for database file
```

## Step 2: Validate Information

### Repository URL Validation
- Must start with `git@` (SSH format)
- Must contain valid org/repo path
- Example: `git@github.com:my-org/my-repo.git`

### Domain Validation
- Must be valid domain format
- No protocol prefix (no `https://`)
- Example: `myapp.com`, `staging.myapp.com`

### Server Hostname Validation
- Must be IP address or valid hostname
- Must be reachable via SSH
- Example: `192.168.1.100`, `server.example.com`

## Step 3: Generate Configuration Files

Based on collected information, generate these files:

### File 1: `deploy.php`

```php
<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/cothinking-dev/deployer-laravel-stack/src/recipe.php';

// ─────────────────────────────────────────────────────────────────────────────
// Application
// ─────────────────────────────────────────────────────────────────────────────

set('application', '{application_name}');
set('repository', '{repository_url}');
set('keep_releases', 5);

// ─────────────────────────────────────────────────────────────────────────────
// Server
// ─────────────────────────────────────────────────────────────────────────────

set('server_hostname', getenv('DEPLOYER_HOST') ?: '{server_hostname}');

host('server')
    ->setHostname(get('server_hostname'))
    ->set('remote_user', 'root')
    ->set('labels', ['stage' => 'server'])
    ->set('deploy_path', '/home/deployer/{app_slug}');

// ─────────────────────────────────────────────────────────────────────────────
// Stack Configuration
// ─────────────────────────────────────────────────────────────────────────────

set('php_version', '{php_version}');
set('node_version', '{node_version}');
set('web_server', '{web_server_mode}');  // 'fpm' or 'octane'

// ─────────────────────────────────────────────────────────────────────────────
// Database Configuration
// ─────────────────────────────────────────────────────────────────────────────

set('db_connection', '{database_type}');
{database_specific_config}

// ─────────────────────────────────────────────────────────────────────────────
// Secrets
// ─────────────────────────────────────────────────────────────────────────────

{secrets_config}

// ─────────────────────────────────────────────────────────────────────────────
// Environments
// ─────────────────────────────────────────────────────────────────────────────

environment('prod', [
    'deploy_path' => '/home/deployer/{app_slug}',
    'domain' => '{production_domain}',
    'db_name' => '{db_name_prod}',
    'redis_db' => {redis_db_prod},
]);

{staging_environment}

// ─────────────────────────────────────────────────────────────────────────────
// Shared Environment Variables
// ─────────────────────────────────────────────────────────────────────────────

set('shared_env', [
    'FILESYSTEM_DISK' => 'local',
    'MAIL_MAILER' => 'log',
]);

{storage_links_config}

// ─────────────────────────────────────────────────────────────────────────────
// Hooks
// ─────────────────────────────────────────────────────────────────────────────

after('deploy:failed', 'deploy:unlock');
```

### Database-Specific Configuration Templates

#### PostgreSQL
```php
set('db_username', 'deployer');
// db_name is set per-environment
```

#### MySQL
```php
set('db_username', 'deployer');
// db_name is set per-environment
```

#### SQLite
```php
add('shared_dirs', ['database']);
add('writable_dirs', ['database']);
// DB_DATABASE path is set in shared_env
set('shared_env', [
    'DB_DATABASE' => '{{deploy_path}}/shared/database/database.sqlite',
]);
```

### Secrets Configuration Templates

#### 1Password Mode
```php
set('secrets', fn () => requireSecrets(
    required: ['DEPLOYER_SUDO_PASS', 'DEPLOYER_DB_PASSWORD', 'DEPLOYER_APP_KEY'],
    optional: []
));
```

Note: For SQLite, remove `DEPLOYER_DB_PASSWORD` from required secrets.

#### Plain ENV Mode
```php
set('secrets', fn () => requireSecrets(
    required: ['DEPLOYER_SUDO_PASS', 'DEPLOYER_APP_KEY'],
    optional: []
));
```

### File 2: `deploy/dep` (wrapper script)

```bash
#!/usr/bin/env bash
# Deployment wrapper for {application_name}
# Generated by deployer-laravel-stack

set -euo pipefail

# ─────────────────────────────────────────────────────────────────────────────
# Project Configuration
# ─────────────────────────────────────────────────────────────────────────────

export DEPLOYER_APP_NAME="${DEPLOYER_APP_NAME:-{application_name}}"
export DEPLOYER_HOST="${DEPLOYER_HOST:-{server_hostname}}"

# ─────────────────────────────────────────────────────────────────────────────
# Delegate to vendor script
# ─────────────────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

exec "$PROJECT_ROOT/vendor/cothinking-dev/deployer-laravel-stack/bin/dep" "$@"
```

### File 3: Secrets File

#### For 1Password (`deploy/secrets.tpl`)
```bash
DEPLOYER_SUDO_PASS=op://{op_vault}/{op_item}/sudo-password
DEPLOYER_DB_PASSWORD=op://{op_vault}/{op_item}/db-password
DEPLOYER_APP_KEY=op://{op_vault}/{op_item}/app-key
```

#### For Plain ENV (`deploy/secrets.env`)
```bash
# WARNING: Never commit this file to git!
DEPLOYER_SUDO_PASS=your-sudo-password-here
DEPLOYER_DB_PASSWORD=your-db-password-here
DEPLOYER_APP_KEY=base64:generate-with-php-artisan-key-generate
```

## Step 4: Post-Generation Instructions

After generating the files, provide these instructions to the user:

### For 1Password Users

1. Create a 1Password item in vault `{op_vault}` named `{op_item}` with fields:
   - `sudo-password` - Server sudo password for deployer user
   - `db-password` - Database password (skip for SQLite)
   - `app-key` - Laravel APP_KEY (generate with `php artisan key:generate --show`)

2. Install 1Password CLI: `brew install 1password-cli`

3. Sign in: `op signin`

### For Plain ENV Users

1. Edit `deploy/secrets.env` and fill in actual values

2. Ensure `deploy/secrets.env` is in `.gitignore`:
   ```bash
   echo "deploy/secrets.env" >> .gitignore
   ```

### For All Users

1. Make the wrapper executable:
   ```bash
   chmod +x deploy/dep
   ```

2. Install the package:
   ```bash
   composer require deployer/deployer:^8.0@alpha cothinking-dev/deployer-laravel-stack:^2.0 --dev
   ```

3. Bootstrap the server:
   ```bash
   ./deploy/dep setup:server server
   ```

4. Provision and deploy:
   ```bash
   ./deploy/dep setup:environment prod
   ```

5. (Optional) Set up staging:
   ```bash
   ./deploy/dep setup:environment staging
   ```

6. (Optional) Migrate existing data:
   ```bash
   ./deploy/dep data:migrate prod
   ```

## Decision Tree

Use this decision tree when information is ambiguous:

```
Database Type?
├── User has existing PostgreSQL → pgsql
├── User has existing MySQL → mysql
├── User wants simple/file-based → sqlite
├── User mentions "production scale" → pgsql (recommend)
└── Unknown → ASK USER

Secrets Management?
├── User mentions 1Password → 1password
├── User mentions "simple" or "basic" → env
├── User is solo developer → env (recommend)
├── User has team → 1password (recommend)
└── Unknown → ASK USER

Web Server Mode?
├── User mentions "Octane" or "high performance" → octane
├── User mentions "FrankenPHP" → octane
├── User has real-time features → octane (recommend)
├── User wants traditional setup → fpm
└── Unknown → fpm (default)

Staging Environment?
├── User mentions "staging" or "testing" → yes
├── User is solo developer → optional (ask)
├── User has team → yes (recommend)
└── Unknown → yes (default)
```

## Validation Checklist

Before finalizing, verify:

- [ ] `repository_url` is SSH format (`git@...`)
- [ ] `server_hostname` is valid IP or hostname
- [ ] `production_domain` has no protocol prefix
- [ ] Database type is one of: `pgsql`, `mysql`, `sqlite`
- [ ] Secrets management is one of: `1password`, `env`
- [ ] Web server mode is one of: `fpm`, `octane`
- [ ] If SQLite, `shared_dirs` includes `database`
- [ ] If using media uploads, `storage_links` is configured
- [ ] Generated files have no placeholder values like `{...}`

## Common Questions to Ask User

If information is missing, ask these questions:

1. "What is your application name?" (for `application_name`)
2. "What is the Git repository URL? (SSH format: git@github.com:org/repo.git)" (for `repository_url`)
3. "What is your server's IP address or hostname?" (for `server_hostname`)
4. "What domain will this application use in production?" (for `production_domain`)
5. "Which database are you using: PostgreSQL, MySQL, or SQLite?" (for `database_type`)
6. "Do you use 1Password for secrets management, or would you prefer a plain .env file?" (for `secrets_management`)
7. "Do you need a staging environment?" (for `staging_enabled`)
8. "Does your application use Laravel Octane, or traditional PHP-FPM?" (for `web_server_mode`)
9. "Does your application have user-uploaded files (media, images)? If so, what directory?" (for `storage_links`)

## Error Recovery

If the user reports issues:

### "Permission denied" on deploy
- Verify SSH key is added to server
- Run `./deploy/dep github:deploy-key server`

### "Database connection failed"
- For PostgreSQL/MySQL: Verify `DEPLOYER_DB_PASSWORD` is correct
- For SQLite: Verify `shared_dirs` includes `database`

### "Health check failed"
- Check Laravel logs: `./deploy/dep artisan:log prod`
- Verify domain DNS points to server
- Check Caddy status: `./deploy/dep caddy:status prod`

### "1Password error"
- Verify 1Password CLI is installed and signed in
- Verify vault and item names match exactly
- Run `op read "op://{vault}/{item}/sudo-password"` to test
