# Deployment Flow

This document describes the task execution order for deployer-laravel-stack.

## Task Execution Order

```
deploy
├── deploy:prepare
│   ├── deploy:info
│   ├── deploy:setup
│   └── deploy:lock
├── deploy:preflight                     ← Pre-flight checks (before deploy:prepare)
├── deploy:release
├── deploy:update_code
├── deploy:shared
│   └── [before] deploy:env              ← Generate .env file
├── deploy:writable
├── deploy:vendors
│   └── [after] artisan:config:fresh     ← Clear and rebuild config cache
│       └── [after] npm:install          ← Install npm dependencies
│           └── [after] npm:build        ← Build assets
│               └── [after] db:fix-sequences (PostgreSQL only)
│                   └── [after] db:ensure-sqlite (SQLite only)
│                       └── [after] migrate:safe  ← Run migrations with backup
├── deploy:symlink
│   ├── [before] deploy:fix-permissions  ← Ensure home dir is traversable
│   ├── [before] artisan:down            ← Put app in maintenance mode
│   ├── [before] horizon:terminate       ← Stop Horizon workers
│   ├── [after] webserver:reload         ← Reload PHP-FPM or Octane
│   ├── [after] artisan:up               ← Bring app back online
│   │   └── [after] artisan:cache:refresh  ← Refresh caches
│   ├── [after] deploy:verify            ← Health check (auto-rollback on failure)
│   └── [after] queue:restart            ← Restart queue workers
├── deploy:unlock
└── deploy:cleanup
    └── [after] deploy:success           ← Success notification
```

## Rollback Flow

On deployment failure, the following tasks execute:

```
deploy:failed
├── deploy:rollback-on-failure
│   └── deploy:symlink (to previous release)
└── deploy:unlock
```

## Web Server Reload

The `webserver:reload` task automatically selects the correct reload mechanism:

- **PHP-FPM mode** (`web_server: 'fpm'`): Runs `php-fpm:restart`
- **Octane mode** (`web_server: 'octane'`): Runs `octane:reload`

## Database-Specific Tasks

### PostgreSQL

- `db:fix-sequences` - Resets auto-increment sequences to prevent duplicate key errors after data import

### SQLite

- `db:ensure-sqlite` - Creates database file and parent directories if they don't exist

### All Databases

- `migrate:safe` - Runs migrations with automatic backup (for PostgreSQL/MySQL)

## Provisioning Flow

First-time server setup:

```
setup:server
├── provision:bootstrap    ← Create deployer user, configure sudo
├── github:generate-key    ← Generate SSH key for GitHub
└── github:deploy-key      ← Add key to GitHub repo

setup:environment
├── deploy:unlock
├── provision:all
│   ├── provision:firewall
│   ├── provision:fail2ban
│   ├── provision:php
│   ├── provision:composer
│   ├── provision:node
│   ├── provision:redis
│   ├── provision:postgres  (or provision:mysql, provision:sqlite)
│   └── provision:caddy
├── caddy:configure
└── deploy
```

## Task Dependencies Diagram

```
                    ┌─────────────────┐
                    │  deploy:prepare │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ deploy:preflight│
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ deploy:release  │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │deploy:update_code│
                    └────────┬────────┘
                             │
              ┌──────────────▼──────────────┐
              │       deploy:shared         │
              │    (deploy:env before)      │
              └──────────────┬──────────────┘
                             │
                    ┌────────▼────────┐
                    │ deploy:writable │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ deploy:vendors  │
                    └────────┬────────┘
                             │
              ┌──────────────▼──────────────┐
              │    artisan:config:fresh     │
              └──────────────┬──────────────┘
                             │
                    ┌────────▼────────┐
                    │   npm:install   │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │    npm:build    │
                    └────────┬────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
┌───────▼───────┐   ┌────────▼────────┐   ┌───────▼───────┐
│db:fix-sequences│   │ db:ensure-sqlite │   │   (skip)     │
│  (PostgreSQL)  │   │    (SQLite)      │   │   (MySQL)    │
└───────┬───────┘   └────────┬────────┘   └───────┬───────┘
        │                    │                    │
        └────────────────────┼────────────────────┘
                             │
                    ┌────────▼────────┐
                    │  migrate:safe   │
                    └────────┬────────┘
                             │
              ┌──────────────▼──────────────┐
              │      deploy:symlink         │
              │  (artisan:down before)      │
              │  (horizon:terminate before) │
              └──────────────┬──────────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
┌───────▼───────┐   ┌────────▼────────┐   ┌───────▼───────┐
│webserver:reload│   │  artisan:up    │   │ deploy:verify │
└───────────────┘   └────────┬────────┘   └───────────────┘
                             │
                    ┌────────▼────────┐
                    │artisan:cache:   │
                    │    refresh      │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ deploy:unlock   │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ deploy:cleanup  │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ deploy:success  │
                    └─────────────────┘
```

## Failure Handling

If any task fails during deployment:

1. `deploy:failed` hook triggers
2. `deploy:rollback-on-failure` reverts symlink to previous release
3. `deploy:unlock` removes deployment lock
4. Web server continues serving the previous release

## Health Check (deploy:verify)

The verification task checks:

- HTTP response from the domain
- Expected status code (200)
- Application not in maintenance mode

If verification fails, automatic rollback is triggered.
