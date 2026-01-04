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
- [Deployer 7.x](https://deployer.org/)
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
    "deployer/deployer": "^7.5",
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
- `$serverHostname` - Your server hostname/IP
- Environment domains, database names, etc.

### 4. Deploy

```bash
# Bootstrap server (creates deployer user, sets up SSH, adds deploy key to GitHub)
./deploy/dep setup:server server

# Provision and deploy to production
./deploy/dep setup:environment prod

# Or step by step:
./deploy/dep provision:all prod
./deploy/dep caddy:configure prod
./deploy/dep deploy prod
```

## File Structure

After setup, your project should have:

```
your-project/
├── deploy.php                    # Main deployer config
└── deploy/
    ├── dep                       # Bash wrapper (handles 1Password + GitHub CLI)
    ├── secrets.tpl               # 1Password secret references
    └── config/
        └── env-extras.php        # Additional .env variables per environment
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

### Database

| Command                               | Description              |
| ------------------------------------- | ------------------------ |
| `./deploy/dep db:check prod`          | Test database connection |
| `./deploy/dep postgres:list-dbs prod` | List all databases       |

### Utilities

| Command                                | Description            |
| -------------------------------------- | ---------------------- |
| `./deploy/dep artisan:log prod`        | Show Laravel log tail  |
| `./deploy/dep artisan:log:follow prod` | Follow Laravel log     |
| `./deploy/dep app:status prod`         | Show deployment status |
| `./deploy/dep ssh prod`                | SSH into server        |

## Configuration Options

### PHP Configuration (deploy.php)

```php
set('php_version', '8.4');     // PHP version to install
set('node_version', '22');      // Node.js major version
set('db_username', 'deployer'); // PostgreSQL username
```

### Host Configuration

```php
host('prod')
    ->setHostname('your-server.com')
    ->set('remote_user', 'deployer')
    ->set('deploy_path', '~/myapp')
    ->set('branch', 'main')
    ->set('domain', 'myapp.com')
    ->set('url', 'https://myapp.com')
    ->set('db_name', 'myapp')
    ->set('redis_db', '0')
    ->set('app_env', 'production')
    ->set('app_debug', 'false')
    ->set('log_level', 'error')
    ->set('tls_mode', 'internal');  // 'internal' for Cloudflare, 'acme' for Let's Encrypt
```

### Environment Extras (deploy/config/env-extras.php)

Add custom .env variables per environment:

```php
<?php

return [
    'common' => [
        'MAIL_MAILER' => 'smtp',
        'MAIL_FROM_ADDRESS' => 'hello@example.com',
    ],
    'prod' => [
        'GTM_ID' => 'GTM-XXXXXXX',
    ],
    'staging' => [
        'GTM_ID' => '',
    ],
];
```

### Secrets

Secrets are injected via 1Password CLI. Reference them in your deploy.php:

```php
set('secrets', function () {
    return [
        'sudo_pass' => getenv('DEPLOYER_SUDO_PASS'),
        'db_password' => getenv('DEPLOYER_DB_PASSWORD'),
        'app_key' => getenv('DEPLOYER_APP_KEY'),
        // Add more as needed
        'stripe_secret' => getenv('DEPLOYER_STRIPE_SECRET') ?: '',
    ];
});
```

Then in `deploy/secrets.tpl`:

```bash
DEPLOYER_STRIPE_SECRET=op://Vault/item/stripe-secret
```

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
4. **Generate .env** - Create environment file
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

# Manually add to GitHub if needed
gh repo deploy-key add - --title "deployer@hostname" -R org/repo
```

### Database connection failed

```bash
# Reset database password
ssh root@your-server "sudo -u postgres psql -c \"ALTER USER deployer WITH PASSWORD 'newpass';\""
```

### Health check failing

```bash
# Check Laravel logs
./deploy/dep artisan:log prod

# Check Caddy status
./deploy/dep caddy:status prod
```

## Multi-Environment Workflow

```bash
# Fresh server with prod + staging
./deploy/dep setup:server server
./deploy/dep provision:all prod
./deploy/dep caddy:all
./deploy/dep deploy:all
```

## License

MIT
