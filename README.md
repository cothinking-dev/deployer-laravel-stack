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
chmod +x ./deploy/dep
```

### 2. Configure 1Password Secrets

Edit `deploy/secrets.tpl` with your 1Password references:

```bash
DEPLOYER_SUDO_PASS=op://YourVault/your-item/sudo-password
DEPLOYER_DB_PASSWORD=op://YourVault/your-item/db-password
DEPLOYER_APP_KEY=op://YourVault/your-item/app-key
```

### 3. Configure deploy.php

Edit `deploy.php` and update:

- `application` - Your app name
- `repository` - Your GitHub repo URL
- `server_hostname` - Your server hostname/IP
- Environment domains, database names, etc.

### 4. Deploy

**Fresh server (3 commands):**

```bash
./deploy/dep setup:server server           # Bootstrap + add GitHub deploy key
./deploy/dep setup:environment prod        # Provision + Caddy + deploy prod
./deploy/dep setup:environment staging     # Add staging (fast - reuses provisioning)
```

**Regular deployments:**

```bash
./deploy/dep deploy prod      # Deploy to production
./deploy/dep deploy staging   # Deploy to staging
./deploy/dep deploy:all       # Deploy to all environments
```

## File Structure

After setup, your project should have:

```
your-project/
├── deploy.php                    # Main deployer config (single file, human-readable)
└── deploy/
    ├── dep                       # Thin wrapper (delegates to recipe's bin/dep)
    └── secrets.tpl               # 1Password secret references
```

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
    ->set('deploy_path', '~/myapp');

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
    'deploy_path' => '~/myapp',
    'domain'      => 'myapp.example.com',
    'db_name'     => 'myapp',
    'redis_db'    => 0,
    'env'         => [
        'GTM_ID' => 'GTM-XXXXXXX',
    ],
]);

environment('staging', [
    'deploy_path' => '~/myapp-staging',
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

### Environment Helper

The `environment()` helper creates hosts with sensible defaults:

```php
environment('prod', [
    'deploy_path' => '~/myapp',      // Required
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

| Setting     | prod        | staging             |
| ----------- | ----------- | ------------------- |
| Deploy path | `~/myapp`   | `~/myapp-staging`   |
| Database    | `myapp`     | `myapp_staging`     |
| Redis DB    | `0`         | `1`                 |
| Domain      | `myapp.com` | `staging.myapp.com` |

### Fresh Server Setup

```bash
./deploy/dep setup:server server           # 1. Bootstrap + GitHub deploy key
./deploy/dep setup:environment prod        # 2. Provision + Caddy + deploy prod
./deploy/dep setup:environment staging     # 3. Add staging (fast)
```

The second `setup:environment` is fast - provisioning tasks are idempotent and skip if already installed.

### Regular Deployments

```bash
./deploy/dep deploy prod      # Deploy to production
./deploy/dep deploy staging   # Deploy to staging
./deploy/dep deploy:all       # Deploy to all environments
```

### Manual Steps (if needed)

```bash
./deploy/dep setup:server server    # Bootstrap deployer user + GitHub key
./deploy/dep provision:all prod     # Install PHP, Postgres, Redis, etc.
./deploy/dep caddy:all              # Configure Caddy for all domains
./deploy/dep deploy:all             # Deploy all environments
```

## Available Commands

### Server Setup

| Command                               | Description                                      |
| ------------------------------------- | ------------------------------------------------ |
| `./deploy/dep setup:server server`    | Bootstrap server + auto-add deploy key to GitHub |
| `./deploy/dep setup:environment prod` | Provision + configure Caddy + deploy             |

### Provisioning

| Command                                   | Description                        |
| ----------------------------------------- | ---------------------------------- |
| `./deploy/dep provision:all prod`         | Run all provisioning tasks         |
| `./deploy/dep provision:bootstrap server` | Create deployer user with SSH keys |
| `./deploy/dep provision:firewall prod`    | Configure UFW firewall             |
| `./deploy/dep provision:fail2ban prod`    | Install and configure Fail2ban     |
| `./deploy/dep provision:php prod`         | Install PHP with extensions        |
| `./deploy/dep provision:composer prod`    | Install Composer globally          |
| `./deploy/dep provision:node prod`        | Install Node.js                    |
| `./deploy/dep provision:postgres prod`    | Install and configure PostgreSQL   |
| `./deploy/dep provision:redis prod`       | Install Redis server               |
| `./deploy/dep provision:caddy prod`       | Install Caddy web server           |

### Deployment

| Command                       | Description                  |
| ----------------------------- | ---------------------------- |
| `./deploy/dep deploy prod`    | Deploy to production         |
| `./deploy/dep deploy staging` | Deploy to staging            |
| `./deploy/dep deploy:all`     | Deploy to all environments   |
| `./deploy/dep rollback prod`  | Rollback to previous release |

### Caddy

| Command                              | Description                          |
| ------------------------------------ | ------------------------------------ |
| `./deploy/dep caddy:configure prod`  | Configure Caddy for domain           |
| `./deploy/dep caddy:all`             | Configure Caddy for all environments |
| `./deploy/dep caddy:reload prod`     | Reload Caddy configuration           |
| `./deploy/dep caddy:status prod`     | Show Caddy status                    |
| `./deploy/dep caddy:list-sites prod` | List configured sites                |

### Services

| Command                             | Description            |
| ----------------------------------- | ---------------------- |
| `./deploy/dep php-fpm:restart prod` | Restart PHP-FPM        |
| `./deploy/dep php-fpm:status prod`  | Show PHP-FPM status    |
| `./deploy/dep redis:status prod`    | Show Redis status      |
| `./deploy/dep postgres:status prod` | Show PostgreSQL status |

### Queue Workers

| Command                          | Description                      |
| -------------------------------- | -------------------------------- |
| `./deploy/dep queue:setup prod`  | Create Supervisor config         |
| `./deploy/dep queue:status prod` | Show queue worker status         |
| `./deploy/dep queue:restart prod`| Restart queue workers            |
| `./deploy/dep queue:stop prod`   | Stop queue workers               |
| `./deploy/dep queue:start prod`  | Start queue workers              |

### Database

| Command                               | Description              |
| ------------------------------------- | ------------------------ |
| `./deploy/dep db:check prod`          | Test database connection |
| `./deploy/dep postgres:list-dbs prod` | List all databases       |

### Environment

| Command                          | Description                         |
| -------------------------------- | ----------------------------------- |
| `./deploy/dep env:show prod`     | Show .env (secrets masked)          |
| `./deploy/dep env:backups prod`  | List .env backups                   |
| `./deploy/dep env:restore prod`  | Restore .env from backup            |
| `./deploy/dep deploy:env:force prod` | Force regenerate .env           |

### GitHub

| Command                                 | Description                            |
| --------------------------------------- | -------------------------------------- |
| `./deploy/dep github:deploy-key server` | Add server's deploy key to GitHub repo |

### Utilities

| Command                                | Description            |
| -------------------------------------- | ---------------------- |
| `./deploy/dep artisan:log prod`        | Show Laravel log tail  |
| `./deploy/dep artisan:log:follow prod` | Follow Laravel log     |
| `./deploy/dep app:status prod`         | Show deployment status |
| `./deploy/dep ssh prod`                | SSH into server        |
| `./deploy/dep deploy:verify prod`      | Run HTTP health check  |

## TLS Modes

| Mode       | Description                                         |
| ---------- | --------------------------------------------------- |
| `internal` | Self-signed certificate (use with Cloudflare proxy) |
| `acme`     | Let's Encrypt automatic certificate                 |

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
./deploy/dep github:deploy-key server

# Or manually add to GitHub
gh repo deploy-key add - --title "deployer@hostname" -R org/repo
```

### Sudo permission denied

The deployer user has NOPASSWD sudo for whitelisted commands only. If a command fails:

```bash
# Check current sudo rules
./deploy/dep provision:sudo:show server

# Re-run bootstrap to update rules (as root)
./deploy/dep provision:bootstrap server
```

### Database connection failed

```bash
# Check database exists
./deploy/dep db:check prod

# Reset database password
ssh root@your-server "sudo -u postgres psql -c \"ALTER USER deployer WITH PASSWORD 'newpass';\""
```

### Health check failing

```bash
# Check Laravel logs
./deploy/dep artisan:log prod

# Check Caddy status
./deploy/dep caddy:status prod

# Manual health check
curl -I https://your-domain.com
```

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
