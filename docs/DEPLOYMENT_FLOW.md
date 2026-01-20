# Deployment Flow

Task execution order for deployer-laravel-stack.

## Deploy Sequence

```
deploy
├── deploy:prepare (info, setup, lock)
├── deploy:preflight          ← Validates disk, RAM, services
├── deploy:release
├── deploy:update_code
├── deploy:shared
│   └── deploy:env            ← Generate .env
├── deploy:writable
├── deploy:vendors
│   └── artisan:config:fresh
│       └── npm:install
│           └── npm:build
│               └── db:fix-sequences (PostgreSQL)
│               └── db:ensure-sqlite (SQLite)
│                   └── migrate:safe
├── deploy:symlink
│   ├── [before] artisan:down
│   ├── [before] horizon:terminate
│   ├── [after] webserver:reload  ← php-fpm:restart or octane:reload
│   ├── [after] artisan:up
│   ├── [after] deploy:verify     ← Health check (auto-rollback on failure)
│   └── [after] queue:restart
├── deploy:unlock
└── deploy:cleanup
```

## Provisioning Sequence

```
setup:server
├── provision:bootstrap    ← Create deployer user, sudo
├── github:generate-key
└── github:deploy-key

setup:environment
├── provision:all (firewall, fail2ban, php, composer, node, redis, database, caddy)
├── caddy:configure
└── deploy
```

## Failure Handling

On deploy failure:
1. `deploy:rollback-on-failure` reverts symlink
2. `deploy:unlock` releases lock
3. Previous release continues serving

## Health Check

`deploy:verify` checks:
- HTTP 200 response
- Not in maintenance mode
- No Laravel error patterns

Auto-rollback triggers if verification fails.
