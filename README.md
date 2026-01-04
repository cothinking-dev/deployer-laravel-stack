# Deployer Laravel Stack

A [Deployer](https://deployer.org/) recipe for Laravel applications with:

- **PHP 8.x** with common extensions
- **PostgreSQL** (localhost only, secure by default)
- **Redis** (localhost only)
- **Caddy** web server with automatic HTTPS
- **UFW Firewall** (SSH, HTTP, HTTPS only)
- **Node.js** for frontend asset building

## Requirements

- Ubuntu 20.04+ server
- PHP 8.2+ locally
- [Deployer 7.x](https://deployer.org/)
- SSH access to your server

## Installation

```bash
composer require --dev cothinking-dev/deployer-laravel-stack
```

For private/development use, add the repository to your `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/cothinking-dev/deployer-laravel-stack.git"
    }
  ],
  "require-dev": {
    "deployer/deployer": "^7.0",
    "cothinking-dev/deployer-laravel-stack": "dev-main"
  }
}
```

## Quick Start

```php
<?php
// deploy.php

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/cothinking-dev/deployer-laravel-stack/src/recipe.php';

set('application', 'My App');
set('repository', 'git@github.com:you/your-repo.git');

// Secrets (use environment variables in production!)
set('secrets', function () {
    return [
        'sudo_pass' => getenv('DEPLOYER_SUDO_PASS'),
        'db_password' => getenv('DEPLOYER_DB_PASSWORD'),
        'app_key' => getenv('DEPLOYER_APP_KEY'),
    ];
});

host('production')
    ->setHostname('your-server.com')
    ->set('remote_user', 'deployer')
    ->set('deploy_path', '~/myapp')
    ->set('branch', 'main')
    ->set('labels', ['stage' => 'prod'])
    ->set('domain', 'myapp.com')
    ->set('url', 'https://myapp.com')
    ->set('db_name', 'myapp')
    ->set('app_env', 'production');
```

## Provisioning a New Server

```bash
# Full server setup (run once per server)
dep provision:all production

# Configure Caddy for your domain
dep caddy:configure production
```

## Deploying

```bash
dep deploy production
```

## Available Tasks

### Provisioning (one-time setup)

| Task                 | Description                      |
| -------------------- | -------------------------------- |
| `provision:all`      | Run all provisioning tasks       |
| `provision:firewall` | Configure UFW firewall           |
| `provision:php`      | Install PHP with extensions      |
| `provision:composer` | Install Composer                 |
| `provision:node`     | Install Node.js                  |
| `provision:postgres` | Install and configure PostgreSQL |
| `provision:redis`    | Install Redis                    |
| `provision:caddy`    | Install Caddy web server         |

### Deployment

| Task          | Description              |
| ------------- | ------------------------ |
| `deploy`      | Full deployment          |
| `deploy:env`  | Generate .env file       |
| `npm:install` | Install npm dependencies |
| `npm:build`   | Build frontend assets    |

### Services

| Task                    | Description                |
| ----------------------- | -------------------------- |
| `php-fpm:restart`       | Restart PHP-FPM            |
| `php-fpm:status`        | Show PHP-FPM status        |
| `caddy:configure`       | Configure Caddy for domain |
| `caddy:reload`          | Reload Caddy configuration |
| `artisan:queue:restart` | Restart queue workers      |
| `artisan:cache:clear`   | Clear all caches           |

### Database

| Task                | Description                  |
| ------------------- | ---------------------------- |
| `db:check`          | Test database connection     |
| `db:reset-password` | Reset database user password |

### Utilities

| Task                        | Description            |
| --------------------------- | ---------------------- |
| `artisan:log`               | Show Laravel log tail  |
| `provision:firewall:status` | Show firewall rules    |
| `app:status`                | Show deployment status |

## Configuration Options

| Option         | Default    | Description                                |
| -------------- | ---------- | ------------------------------------------ |
| `php_version`  | `8.4`      | PHP version to install                     |
| `node_version` | `22`       | Node.js major version                      |
| `db_username`  | `deployer` | PostgreSQL username                        |
| `secrets`      | (required) | Array with sudo_pass, db_password, app_key |
| `domain`       | (required) | Domain for Caddy configuration             |
| `url`          | (required) | Full URL for APP_URL                       |
| `db_name`      | (required) | PostgreSQL database name                   |

## Security

- PostgreSQL and Redis listen on localhost only
- UFW firewall allows only SSH (22), HTTP (80), HTTPS (443)
- SSH has rate limiting enabled
- No secrets are stored in the recipe

## Using with 1Password CLI

Create a wrapper script to inject secrets:

```bash
#!/bin/bash
# deploy/dep
op run --env-file="deploy/secrets.tpl" -- vendor/bin/dep "$@"
```

With a secrets template:

```bash
# deploy/secrets.tpl
DEPLOYER_SUDO_PASS=op://Vault/item/sudo-password
DEPLOYER_DB_PASSWORD=op://Vault/item/db-password
DEPLOYER_APP_KEY=op://Vault/item/app-key
```

## License

MIT
