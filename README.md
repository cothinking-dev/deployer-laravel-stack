# Deployer Laravel Stack

Zero-downtime Laravel deployments with automated server provisioning.

## Quick Start

```bash
# Install
composer require deployer/deployer:^8.0@alpha cothinking-dev/deployer-laravel-stack:^2.0 --dev

# Run setup wizard
vendor/bin/dep init
```

Then deploy:

```bash
./deploy/dep setup:server server      # Bootstrap server
./deploy/dep setup:environment prod   # Provision + deploy
```

## What's Included

- PHP 8.4 with Caddy (automatic HTTPS)
- SQLite, PostgreSQL, or MySQL
- Redis for caching/queues
- UFW firewall + Fail2ban
- Laravel Octane support (optional)

## Documentation Index

| Document | Audience | Purpose |
|----------|----------|---------|
| [Quick Setup](#quick-start) | Everyone | Get running in 5 minutes |
| [Setup Methods](#setup-methods) | Everyone | Choose your preferred setup approach |
| [Common Commands](#common-commands) | Everyone | Day-to-day deployment operations |
| [docs/AI_SETUP_GUIDE.md](docs/AI_SETUP_GUIDE.md) | **AI Agents** | Complete setup instructions for LLMs |
| [DATABASE_CONFIGURATION.md](DATABASE_CONFIGURATION.md) | Developers | Detailed database setup (PostgreSQL, MySQL, SQLite) |
| [docs/HTTPS_PROXY_GUIDE.md](docs/HTTPS_PROXY_GUIDE.md) | Developers | Fix mixed content and proxy issues |
| [docs/DEPLOYMENT_FLOW.md](docs/DEPLOYMENT_FLOW.md) | Advanced | Task execution order and hooks |

---

## Setup Methods

### AI-Assisted (Recommended for AI users)

```
Read docs/AI_SETUP_GUIDE.md and help me configure deployment.
```

The guide contains everything an AI agent needs: required information, file templates, CLI commands, and verification steps.

### Interactive Wizard

```bash
vendor/bin/dep init
```

Prompts for: app name, repository, server, database type, secrets management.

### Manual Configuration

```bash
cp vendor/cothinking-dev/deployer-laravel-stack/examples/deploy.php ./deploy.php
mkdir -p deploy
cp vendor/cothinking-dev/deployer-laravel-stack/examples/deploy/dep ./deploy/dep
chmod +x ./deploy/dep
```

Edit `deploy.php`:

```php
set('application', 'My App');
set('repository', 'git@github.com:org/repo.git');
set('server_hostname', 'your-server.example.com');
set('db_connection', 'sqlite');  // or 'pgsql', 'mysql'

environment('prod', [
    'deploy_path' => '/home/deployer/my-app',
    'domain' => 'my-app.com',
    'db_name' => 'my_app',
    'redis_db' => 0,
]);
```

---

## Common Commands

### Deployment

```bash
./deploy/dep deploy prod          # Deploy
./deploy/dep rollback prod        # Rollback to previous
./deploy/dep deploy:verify prod   # Health check
```

### Database

```bash
./deploy/dep db:backup prod       # Create backup
./deploy/dep db:restore prod      # Restore from backup
```

### Diagnostics

```bash
./deploy/dep artisan:log prod     # View Laravel logs
./deploy/dep ssh prod             # SSH into server
```

---

## GitHub Actions CI/CD

```bash
# Generate CI key
./deploy/dep github:ci-key server

# Set secrets (per environment)
gh secret set SSH_PRIVATE_KEY --env production < key-file
gh secret set DEPLOYER_SUDO_PASS --env production
gh secret set DEPLOYER_APP_KEY --env production

# Copy workflow
cp vendor/cothinking-dev/deployer-laravel-stack/examples/.github/workflows/deploy.yml .github/workflows/
```

| Branch | Deploys To |
|--------|------------|
| `main` | Production |
| `develop` | Staging |

---

## Requirements

**Local**: PHP 8.2+, Composer, GitHub CLI (`gh`)

**Server**: Ubuntu 20.04+, root SSH access

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Health check failing | `./deploy/dep artisan:log prod` |
| Permission denied | `./deploy/dep provision:bootstrap server` |
| Mixed content errors | See [HTTPS_PROXY_GUIDE.md](docs/HTTPS_PROXY_GUIDE.md) |
| Storage 404s | `./deploy/dep storage:link-custom prod` |

---

## License

MIT
