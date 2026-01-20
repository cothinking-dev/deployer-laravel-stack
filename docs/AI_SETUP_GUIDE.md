# AI Setup Guide

Instructions for AI agents to configure deployer-laravel-stack.

## Required Information

Collect these values before generating files:

| Field | Required | Example |
|-------|----------|---------|
| `application_name` | Yes | "My Application" |
| `repository_url` | Yes | `git@github.com:org/repo.git` |
| `server_hostname` | Yes | `192.168.1.100` or `server.example.com` |
| `production_domain` | Yes | `myapp.com` |
| `database_type` | Yes | `sqlite`, `pgsql`, or `mysql` |
| `secrets_management` | Yes | `1password` or `env` |
| `web_server_mode` | No (default: `fpm`) | `fpm` or `octane` |
| `staging_domain` | No | `staging.myapp.com` |
| `php_version` | No (default: `8.4`) | `8.2`, `8.3`, `8.4` |

### Derived Values

```
app_slug = lowercase(application_name).replace(" ", "-")
db_name_prod = app_slug.replace("-", "_")
db_name_staging = db_name_prod + "_staging"
deploy_path_prod = /home/deployer/{app_slug}
```

## File Generation

Generate these three files:

### 1. deploy.php

```php
<?php
namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/cothinking-dev/deployer-laravel-stack/src/recipe.php';

set('application', '{application_name}');
set('repository', '{repository_url}');
set('keep_releases', 5);
set('server_hostname', getenv('DEPLOYER_HOST') ?: '{server_hostname}');

host('server')
    ->setHostname(get('server_hostname'))
    ->set('remote_user', 'root')
    ->set('labels', ['stage' => 'server'])
    ->set('deploy_path', '/home/deployer/{app_slug}');

set('php_version', '{php_version}');
set('node_version', '22');
set('web_server', '{web_server_mode}');
set('db_connection', '{database_type}');

// Database config (skip db_username for sqlite)
{%- if database_type != 'sqlite' %}
set('db_username', 'deployer');
{%- else %}
add('shared_dirs', ['database']);
add('writable_dirs', ['database']);
{%- endif %}

// Secrets
set('secrets', fn () => requireSecrets(
    required: [{%- if database_type != 'sqlite' %}'DEPLOYER_DB_PASSWORD', {%- endif %}'DEPLOYER_SUDO_PASS', 'DEPLOYER_APP_KEY'],
));

environment('prod', [
    'deploy_path' => '/home/deployer/{app_slug}',
    'domain' => '{production_domain}',
    'db_name' => '{db_name_prod}',
    'redis_db' => 0,
]);

environment('staging', [
    'deploy_path' => '/home/deployer/{app_slug}-staging',
    'domain' => '{staging_domain}',
    'db_name' => '{db_name_staging}',
    'redis_db' => 1,
]);

set('shared_env', [
    'FILESYSTEM_DISK' => 'local',
    {%- if database_type == 'sqlite' %}
    'DB_DATABASE' => '{{deploy_path}}/shared/database/database.sqlite',
    {%- endif %}
]);

after('deploy:failed', 'deploy:unlock');
```

### 2. deploy/dep

```bash
#!/usr/bin/env bash
set -euo pipefail

export DEPLOYER_APP_NAME="${DEPLOYER_APP_NAME:-{application_name}}"
export DEPLOYER_HOST="${DEPLOYER_HOST:-{server_hostname}}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

exec "$PROJECT_ROOT/vendor/cothinking-dev/deployer-laravel-stack/bin/dep" "$@"
```

Make executable: `chmod +x deploy/dep`

### 3. Secrets File

**For 1Password** (`deploy/secrets.tpl`):
```bash
DEPLOYER_SUDO_PASS=op://{vault}/{item}/sudo-password
DEPLOYER_DB_PASSWORD=op://{vault}/{item}/db-password
DEPLOYER_APP_KEY=op://{vault}/{item}/app-key
```

**For plain env** (`deploy/secrets.env`):
```bash
DEPLOYER_SUDO_PASS=CHANGE_ME
DEPLOYER_DB_PASSWORD=CHANGE_ME
DEPLOYER_APP_KEY=CHANGE_ME
```

Add to `.gitignore`: `echo "deploy/secrets.env" >> .gitignore`

## Deployment Commands

Execute in order:

```bash
# 1. Bootstrap server
./deploy/dep setup:server server

# 2. Provision and deploy
./deploy/dep setup:environment prod

# 3. (Optional) Staging
./deploy/dep setup:environment staging
```

## GitHub Actions Setup

```bash
# Generate CI key
./deploy/dep github:ci-key server
# Save the private key output

# Set secrets per environment
gh secret set SSH_PRIVATE_KEY --env production < key-file
gh secret set DEPLOYER_SUDO_PASS --env production --body "password"
gh secret set DEPLOYER_APP_KEY --env production --body "base64:key"
gh secret set DEPLOYER_DB_PASSWORD --env production --body "password"  # Skip for SQLite

# Copy workflow
mkdir -p .github/workflows
cp vendor/cothinking-dev/deployer-laravel-stack/examples/.github/workflows/deploy.yml .github/workflows/
```

## Verification

```bash
./deploy/dep deploy:verify prod     # Health check
./deploy/dep artisan:log prod       # Check logs
./deploy/dep list                   # Available commands
```

## Octane Configuration

When `web_server_mode=octane`, configure per-environment ports:

```php
environment('prod', [
    // ...
    'octane_port' => 8000,
    'octane_admin_port' => 2019,
]);

environment('staging', [
    // ...
    'octane_port' => 8001,
    'octane_admin_port' => 2020,
]);
```

## HTTPS Configuration

Ensure the Laravel app has:

**AppServiceProvider.php**:
```php
public function boot(): void
{
    if ($this->app->environment('production')) {
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }
}
```

**bootstrap/app.php**:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
})
```

## Error Recovery

| Error | Solution |
|-------|----------|
| Permission denied (sudo) | `./deploy/dep provision:bootstrap server` |
| Database connection failed | `./deploy/dep db:check prod` |
| Health check failed | `./deploy/dep artisan:log prod` |
| 1Password error | `op signin` then test: `op read "op://vault/item/field"` |
| Deploy key not working | `./deploy/dep github:deploy-key server` |

## Decision Guidance

```
Database Type?
├── Existing PostgreSQL → pgsql
├── Existing MySQL → mysql
├── Simple/file-based → sqlite
└── Unknown → ASK (recommend pgsql for production scale)

Secrets Management?
├── Team environment → 1password
├── Solo developer → env
└── CI/CD only → env (secrets via GitHub Actions)

Web Server?
├── Laravel Octane installed → octane
├── Real-time/WebSockets → octane
└── Traditional setup → fpm (default)
```
