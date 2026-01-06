# Deployer Laravel Stack

A [Deployer](https://deployer.org/) recipe for Laravel applications with automated server provisioning and zero-downtime deployments.

## What's Included

- **PHP 8.4** with common extensions (configurable)
- **PostgreSQL** (localhost only, secure by default)
- **Redis** (localhost only)
- **Caddy** web server with automatic HTTPS
- **UFW Firewall** (SSH rate-limited, HTTP, HTTPS)
- **Fail2ban** for brute force protection
- **Node.js 22** for frontend asset building (configurable)

## Requirements

**Local Machine:**

- PHP 8.2+
- [Deployer 8.x](https://deployer.org/)
- [1Password CLI](https://developer.1password.com/docs/cli/) (`brew install 1password-cli`)
- [GitHub CLI](https://cli.github.com/) (`brew install gh`)
- [direnv](https://direnv.net/) (`brew install direnv`) - optional but recommended

**Server:**

- Ubuntu 20.04+ (fresh install recommended)
- SSH access as root (for initial bootstrap)

## Installation

Add the repository to your `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/cothinking-dev/deployer-laravel-stack.git"
    }
  ],
  "require-dev": {
    "deployer/deployer": "^8.0@alpha",
    "cothinking-dev/deployer-laravel-stack": "^2.0"
  }
}
```

Then run:

```bash
composer update
```

## Quick Start

### 1. Copy Example Files

```bash
cp vendor/cothinking-dev/deployer-laravel-stack/examples/deploy.php ./deploy.php
cp -r vendor/cothinking-dev/deployer-laravel-stack/examples/deploy ./deploy
cp vendor/cothinking-dev/deployer-laravel-stack/examples/.envrc ./.envrc
chmod +x ./deploy/dep
```

### 2. Set Up direnv (Recommended)

Using direnv allows you to run `dep` from any subdirectory without the `./deploy/` prefix:

```bash
# Install direnv if not already installed
brew install direnv

# Add hook to your shell (add to ~/.zshrc or ~/.bashrc)
eval "$(direnv hook zsh)"

# Allow the .envrc file
direnv allow
```

Now you can run commands from anywhere in the project:

```bash
dep deploy prod          # Instead of ./deploy/dep deploy prod
cd app/Models && dep deploy staging  # Works from subdirectories
```

### 3. Configure 1Password Secrets

Edit `deploy/secrets.tpl` with your 1Password references:

```bash
DEPLOYER_SUDO_PASS=op://YourVault/your-item/sudo-password
DEPLOYER_DB_PASSWORD=op://YourVault/your-item/db-password
DEPLOYER_APP_KEY=op://YourVault/your-item/app-key
```

### 4. Configure deploy.php

Edit `deploy.php` and update:

- `application` - Your app name
- `repository` - Your GitHub repo URL
- `server_hostname` - Your server hostname/IP
- Environment domains, database names, etc.

### 5. Configure deploy/dep

Edit `deploy/dep` and update the project-specific settings:

```bash
export DEPLOYER_APP_NAME="${DEPLOYER_APP_NAME:-My Application}"
export DEPLOYER_HOST="${DEPLOYER_HOST:-your-server.example.com}"
```

### 6. Deploy

**Fresh server (3 commands):**

```bash
dep setup:server server           # Bootstrap + add GitHub deploy key
dep setup:environment prod        # Provision + Caddy + deploy prod
dep setup:environment staging     # Add staging (fast - reuses provisioning)
```

**Regular deployments:**

```bash
dep deploy prod      # Deploy to production
dep deploy staging   # Deploy to staging
dep deploy:all       # Deploy to all environments
```

## File Structure

After setup, your project should have:

```
your-project/
├── deploy.php          # Main deployer config
├── .envrc              # direnv config (adds deploy/ to PATH)
└── deploy/
    ├── dep             # Thin wrapper with project config
    └── secrets.tpl     # 1Password secret references
```

## Architecture

The deployment system has two layers:

1. **Vendor script** (`vendor/.../bin/dep`) - Contains all the robust logic:
   - Project root detection (works from any subdirectory)
   - 1Password integration
   - Color output with TTY detection
   - Prerequisite validation
   - Multi-environment commands

2. **Project wrapper** (`deploy/dep`) - Thin wrapper that:
   - Sets project-specific environment variables
   - Displays project banner
   - Delegates to vendor script

This design means:
- All robust logic is shared across projects
- Each project only configures its own settings
- Updates to the package benefit all projects

## Configuration

### deploy.php Structure

The config is organized into clearly labeled sections:

```php
<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/cothinking-dev/deployer-laravel-stack/src/recipe.php';

// ─────────────────────────────────────────────────────────────────────────────
// Application
// ─────────────────────────────────────────────────────────────────────────────

set('application', 'My Application');
set('repository', 'git@github.com:your-org/your-repo.git');
set('keep_releases', 5);

// ─────────────────────────────────────────────────────────────────────────────
// Server
// ─────────────────────────────────────────────────────────────────────────────

set('server_hostname', getenv('DEPLOYER_HOST') ?: 'your-server.example.com');

host('server')
    ->setHostname(get('server_hostname'))
    ->set('remote_user', 'root')
    ->set('labels', ['stage' => 'server'])
    ->set('deploy_path', '/home/deployer/myapp');

// ─────────────────────────────────────────────────────────────────────────────
// Shared Resources
// ─────────────────────────────────────────────────────────────────────────────

// Laravel recipe already sets storage and .env
// Add any custom shared directories here:
// add('shared_dirs', ['custom-uploads']);
// add('writable_dirs', ['custom-uploads']);

// ─────────────────────────────────────────────────────────────────────────────
// Secrets (from 1Password via environment)
// ─────────────────────────────────────────────────────────────────────────────

set('secrets', fn () => requireSecrets(
    required: ['DEPLOYER_SUDO_PASS', 'DEPLOYER_DB_PASSWORD', 'DEPLOYER_APP_KEY'],
    optional: ['DEPLOYER_STRIPE_KEY' => '']
));

// ─────────────────────────────────────────────────────────────────────────────
// Environments
// ─────────────────────────────────────────────────────────────────────────────

environment('prod', [
    'deploy_path' => '/home/deployer/myapp',
    'domain'      => 'myapp.example.com',
    'db_name'     => 'myapp',
    'redis_db'    => 0,
    'env'         => [
        'GTM_ID' => 'GTM-XXXXXXX',
    ],
]);

environment('staging', [
    'deploy_path' => '/home/deployer/myapp-staging',
    'domain'      => 'staging.myapp.example.com',
    'db_name'     => 'myapp_staging',
    'redis_db'    => 1,
    'app_debug'   => true,
    'log_level'   => 'debug',
    'env'         => [
        'GTM_ID' => '',
    ],
]);

// ─────────────────────────────────────────────────────────────────────────────
// Shared Environment Variables
// ─────────────────────────────────────────────────────────────────────────────

set('shared_env', [
    'FILESYSTEM_DISK' => 'local',
    'MAIL_MAILER'     => 'smtp',
    'STRIPE_KEY'      => '{stripe_key}',  // References secrets['stripe_key']
]);

// ─────────────────────────────────────────────────────────────────────────────
// Queue Workers (optional)
// ─────────────────────────────────────────────────────────────────────────────

// set('queue_worker_name', fn () => 'myapp-' . getStage() . '-worker');

// ─────────────────────────────────────────────────────────────────────────────
// Hooks
// ─────────────────────────────────────────────────────────────────────────────

after('deploy:failed', 'deploy:unlock');
```

### Environment Variables

The `deploy/dep` wrapper accepts these environment variables:

| Variable | Default | Description |
| -------- | ------- | ----------- |
| `DEPLOYER_SECRETS_FILE` | `deploy/secrets.tpl` | Path to 1Password secrets template |
| `DEPLOYER_ENVIRONMENTS` | `prod staging server` | Space-separated list of valid environments |
| `DEPLOYER_PROD_ENVIRONMENTS` | `prod staging` | Environments for `deploy:all` and `caddy:all` |
| `DEPLOYER_APP_NAME` | `Application` | Displayed in banner |
| `DEPLOYER_HOST` | (none) | Server hostname |

### Environment Helper

The `environment()` helper creates hosts with sensible defaults:

```php
environment('prod', [
    'deploy_path' => '/home/deployer/myapp',  // Required
    'domain'      => 'myapp.com',    // Required
    'db_name'     => 'myapp',        // Required
    'redis_db'    => 0,              // Required
    'app_debug'   => false,          // Default: false (true for non-prod)
    'log_level'   => 'error',        // Default: 'error'
    'tls_mode'    => 'internal',     // Default: 'internal'
    'env'         => [],             // Per-environment .env overrides
]);
```

### Secret Placeholders

Use `{secret_key}` syntax in `shared_env` to reference secrets:

```php
set('secrets', fn () => requireSecrets(
    required: ['DEPLOYER_SUDO_PASS', 'DEPLOYER_DB_PASSWORD', 'DEPLOYER_APP_KEY'],
    optional: ['DEPLOYER_STRIPE_KEY' => '']  // Maps to secrets['stripe_key']
));

set('shared_env', [
    'STRIPE_KEY' => '{stripe_key}',  // Resolved from secrets['stripe_key']
]);
```

### Secrets (deploy/secrets.tpl)

```bash
DEPLOYER_SUDO_PASS=op://Vault/Server/sudo-password
DEPLOYER_DB_PASSWORD=op://Vault/Server/db-password
DEPLOYER_APP_KEY=op://Vault/App/laravel-key
DEPLOYER_STRIPE_KEY=op://Vault/Stripe/secret-key
```

## Multi-Environment Setup

Prod and staging are **separate deployments** on the same server:

| Setting     | prod                       | staging                           |
| ----------- | -------------------------- | --------------------------------- |
| Deploy path | `/home/deployer/myapp`     | `/home/deployer/myapp-staging`    |
| Database    | `myapp`     | `myapp_staging`     |
| Redis DB    | `0`         | `1`                 |
| Domain      | `myapp.com` | `staging.myapp.com` |

### Fresh Server Setup

```bash
dep setup:server server           # 1. Bootstrap + GitHub deploy key
dep setup:environment prod        # 2. Provision + Caddy + deploy prod
dep setup:environment staging     # 3. Add staging (fast)
```

The second `setup:environment` is fast - provisioning tasks are idempotent and skip if already installed.

### Regular Deployments

```bash
dep deploy prod      # Deploy to production
dep deploy staging   # Deploy to staging
dep deploy:all       # Deploy to all environments
```

### Manual Steps (if needed)

```bash
dep setup:server server    # Bootstrap deployer user + GitHub key
dep provision:all prod     # Install PHP, Postgres, Redis, etc.
dep caddy:all              # Configure Caddy for all domains
dep deploy:all             # Deploy all environments
```

## Available Commands

### Server Setup

| Command | Description |
| ------- | ----------- |
| `dep setup:server server` | Bootstrap server + auto-add deploy key to GitHub |
| `dep setup:environment prod` | Provision + configure Caddy + deploy |

### Provisioning

| Command | Description |
| ------- | ----------- |
| `dep provision:all prod` | Run all provisioning tasks |
| `dep provision:bootstrap server` | Create deployer user with SSH keys |
| `dep provision:firewall prod` | Configure UFW firewall |
| `dep provision:fail2ban prod` | Install and configure Fail2ban |
| `dep provision:php prod` | Install PHP with extensions |
| `dep provision:composer prod` | Install Composer globally |
| `dep provision:node prod` | Install Node.js |
| `dep provision:postgres prod` | Install and configure PostgreSQL |
| `dep provision:redis prod` | Install Redis server |
| `dep provision:caddy prod` | Install Caddy web server |

### Deployment

| Command | Description |
| ------- | ----------- |
| `dep deploy prod` | Deploy to production |
| `dep deploy staging` | Deploy to staging |
| `dep deploy:all` | Deploy to all environments |
| `dep rollback prod` | Rollback to previous release |

### Caddy

| Command | Description |
| ------- | ----------- |
| `dep caddy:configure prod` | Configure Caddy for domain |
| `dep caddy:all` | Configure Caddy for all environments |
| `dep caddy:reload prod` | Reload Caddy configuration |
| `dep caddy:status prod` | Show Caddy status |
| `dep caddy:list-sites prod` | List configured sites |

### Services

| Command | Description |
| ------- | ----------- |
| `dep php-fpm:restart prod` | Restart PHP-FPM |
| `dep php-fpm:status prod` | Show PHP-FPM status |
| `dep redis:status prod` | Show Redis status |
| `dep postgres:status prod` | Show PostgreSQL status |

### Queue Workers

| Command | Description |
| ------- | ----------- |
| `dep queue:setup prod` | Create Supervisor config |
| `dep queue:status prod` | Show queue worker status |
| `dep queue:restart prod` | Restart queue workers |
| `dep queue:stop prod` | Stop queue workers |
| `dep queue:start prod` | Start queue workers |

### Database

| Command | Description |
| ------- | ----------- |
| `dep db:check prod` | Test database connection |
| `dep postgres:list-dbs prod` | List all databases |

### Environment

| Command | Description |
| ------- | ----------- |
| `dep env:show prod` | Show .env (secrets masked) |
| `dep env:backups prod` | List .env backups |
| `dep env:restore prod` | Restore .env from backup |
| `dep deploy:env:force prod` | Force regenerate .env |

### GitHub

| Command | Description |
| ------- | ----------- |
| `dep github:generate-key server` | Generate project-specific deploy key |
| `dep github:deploy-key server` | Add deploy key to GitHub repo |
| `dep github:show-key server` | Show the deploy key for this project |

### Utilities

| Command | Description |
| ------- | ----------- |
| `dep artisan:log prod` | Show Laravel log tail |
| `dep artisan:log:follow prod` | Follow Laravel log |
| `dep app:status prod` | Show deployment status |
| `dep ssh prod` | SSH into server |
| `dep deploy:verify prod` | Run HTTP health check |

## TLS Modes

| Mode | Description |
| ---- | ----------- |
| `internal` | Self-signed certificate (use with Cloudflare proxy) |
| `acme` | Let's Encrypt automatic certificate |

## Security Features

- PostgreSQL and Redis listen on localhost only
- UFW firewall allows only SSH (rate-limited), HTTP, HTTPS
- Fail2ban protects against brute force attacks
- Deployer user has restricted sudo access (whitelisted commands only)
- No secrets stored on disk or in git

## Deployment Flow

1. **Lock** - Prevent concurrent deployments
2. **Release** - Create new release directory
3. **Update Code** - Clone/archive from git
4. **Generate .env** - Create environment file from secrets
5. **Shared** - Symlink shared files/directories
6. **Vendors** - Install composer dependencies
7. **NPM** - Install and build frontend assets
8. **Artisan** - Run Laravel optimizations and migrations
9. **Symlink** - Atomic switch to new release
10. **Verify** - Health check (HTTP 200)
11. **Cleanup** - Remove old releases

## Troubleshooting

### Deploy key not working

```bash
# Check if key exists on server
ssh deployer@your-server "cat ~/.ssh/id_ed25519.pub"

# Re-run the GitHub deploy key task
dep github:deploy-key server

# Or manually add to GitHub
gh repo deploy-key add - --title "deployer@hostname" -R org/repo
```

### Sudo permission denied

The deployer user has NOPASSWD sudo for whitelisted commands only. If a command fails:

```bash
# Check current sudo rules
dep provision:sudo:show server

# Re-run bootstrap to update rules (as root)
dep provision:bootstrap server
```

### Database connection failed

```bash
# Check database exists
dep db:check prod

# Reset database password
ssh root@your-server "sudo -u postgres psql -c \"ALTER USER deployer WITH PASSWORD 'newpass';\""
```

### Health check failing

```bash
# Check Laravel logs
dep artisan:log prod

# Check Caddy status
dep caddy:status prod

# Manual health check
curl -I https://your-domain.com
```

## Multiple Projects on One Server

This recipe supports hosting multiple Laravel projects on the same server. Each project maintains its own configuration, and shared services (Caddy, PostgreSQL, Redis) are designed to handle multiple tenants.

### How It Works

**Caddy** uses per-domain config files:
```
/etc/caddy/sites-enabled/
├── project-a-com.conf           → /home/deployer/project-a/current/public
├── staging-project-a-com.conf   → /home/deployer/project-a-staging/current/public
├── project-b-com.conf           → /home/deployer/project-b/current/public
└── staging-project-b-com.conf   → /home/deployer/project-b-staging/current/public
```

Each domain gets its own config file, so there are no conflicts.

### Resource Allocation

When setting up multiple projects, coordinate these resources:

| Resource | Project A | Project B |
| -------- | --------- | --------- |
| Deploy path (prod) | `/home/deployer/project-a` | `/home/deployer/project-b` |
| Deploy path (staging) | `/home/deployer/project-a-staging` | `/home/deployer/project-b-staging` |
| Database (prod) | `project_a` | `project_b` |
| Database (staging) | `project_a_staging` | `project_b_staging` |
| Redis DB (prod) | `0` | `2` |
| Redis DB (staging) | `1` | `3` |
| Queue worker | `project-a-prod-worker` | `project-b-prod-worker` |

### Redis DB Numbers

Redis supports databases 0-15. Allocate unique numbers per environment:

```php
// Project A
environment('prod', ['redis_db' => 0, ...]);
environment('staging', ['redis_db' => 1, ...]);

// Project B
environment('prod', ['redis_db' => 2, ...]);
environment('staging', ['redis_db' => 3, ...]);
```

### Setting Up a Second Project

For the second (and subsequent) projects, provisioning is fast since services are already installed:

```bash
# From Project B's directory
dep setup:server server           # Adds Project B's deploy key to GitHub
dep setup:environment prod        # Creates DB, Caddy config, deploys
dep setup:environment staging     # Creates staging DB, Caddy config, deploys
```

The `provision:*` tasks are idempotent - they skip installation if the service already exists.

### Shared Services

These services are shared across all projects:

| Service | Sharing Model |
| ------- | ------------- |
| **PHP-FPM** | Single pool, shared by all projects |
| **PostgreSQL** | One instance, separate databases per project |
| **Redis** | One instance, separate DB numbers per project |
| **Caddy** | One instance, separate site configs per domain |
| **Supervisor** | One instance, separate worker configs per project |

### Deploy Keys (Important!)

GitHub requires deploy keys to be unique per repository. You **cannot** use the same SSH key for multiple repos.

**For multi-project servers, generate project-specific keys:**

```bash
# From Project A's directory
dep github:generate-key server    # Creates ~/.ssh/deploy_org_project-a
dep github:deploy-key server      # Adds to GitHub

# From Project B's directory
dep github:generate-key server    # Creates ~/.ssh/deploy_org_project-b
dep github:deploy-key server      # Adds to GitHub
```

This creates separate keys per project:
```
~/.ssh/
├── deploy_Moojing-Global_moojing-global.com-laravel      # Project A private key
├── deploy_Moojing-Global_moojing-global.com-laravel.pub  # Project A public key
├── deploy_cothinking-dev_pixaproof                        # Project B private key
├── deploy_cothinking-dev_pixaproof.pub                    # Project B public key
└── config                                                  # SSH config with host aliases
```

The recipe also configures `~/.ssh/config` with host aliases so each project uses its own key automatically.

**If you already have projects sharing the old single key:**
1. Run `github:generate-key server` from each project
2. Add the new keys to each GitHub repo
3. Remove the old shared key from GitHub

### Considerations

1. **Memory** - Each project's queue workers consume memory. Monitor server resources.
2. **PHP-FPM pool** - All projects share one pool. For isolation, consider separate pools (not covered by this recipe).
3. **Secrets** - Each project has its own `deploy/secrets.tpl` with its own 1Password references.
4. **Deploy user** - All projects share the `deployer` user but each has its own deploy key.

## Upgrading from v2.x (env-extras.php)

If you're upgrading from a version that used `deploy/config/env-extras.php`:

1. Move your env vars from `env-extras.php` into `deploy.php`:

   **Before (`deploy/config/env-extras.php`):**
   ```php
   return [
       'common' => ['MAIL_MAILER' => 'smtp'],
       'prod' => ['GTM_ID' => 'GTM-XXX'],
       'staging' => ['GTM_ID' => ''],
   ];
   ```

   **After (`deploy.php`):**
   ```php
   set('shared_env', [
       'MAIL_MAILER' => 'smtp',
   ]);

   environment('prod', [
       // ... other config
       'env' => ['GTM_ID' => 'GTM-XXX'],
   ]);

   environment('staging', [
       // ... other config
       'env' => ['GTM_ID' => ''],
   ]);
   ```

2. Delete `deploy/config/env-extras.php` and the `deploy/config/` directory.

3. Replace the `foreach ($environments)` loop with `environment()` calls.

## License

MIT
