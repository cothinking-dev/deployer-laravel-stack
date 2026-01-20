# Deployer Laravel Stack

A [Deployer](https://deployer.org/) recipe for Laravel applications with automated server provisioning and zero-downtime deployments.

## What's Included

- **PHP 8.4** with common extensions (configurable)
- **SQLite** (default), **PostgreSQL**, or **MySQL** database support
- **Redis** for caching and queues (optional)
- **Caddy** web server with automatic HTTPS
- **Laravel Octane** support with FrankenPHP (optional)
- **UFW Firewall** (SSH rate-limited, HTTP, HTTPS)
- **Fail2ban** for brute force protection
- **Node.js 22** for frontend asset building (configurable)

## Requirements

**Local Machine:**

- PHP 8.2+
- [Deployer 8.x](https://deployer.org/)
- [1Password CLI](https://developer.1password.com/docs/cli/) (`brew install 1password-cli`) - optional
- [GitHub CLI](https://cli.github.com/) (`brew install gh`) - for deploy key management

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

## Setup Methods

Choose the setup method that works best for you:

| Method | Best For | Time |
|--------|----------|------|
| [AI-Assisted](#method-1-ai-assisted-setup) | Users with AI coding assistants (Claude, Cursor, etc.) | ~5 min |
| [Interactive Wizard](#method-2-interactive-wizard) | Quick setup with guided prompts | ~5 min |
| [Manual Configuration](#method-3-manual-configuration) | Full control, existing configs | ~15 min |

---

### Method 1: AI-Assisted Setup

If you're using an AI coding assistant (Claude Code, Cursor, Copilot, etc.), point it to the setup guide:

```
Read docs/AI_SETUP_GUIDE.md and help me configure deployment for this Laravel project.
```

The AI will:
1. Ask you for required information (server, domain, database type, etc.)
2. Generate all configuration files
3. Provide step-by-step deployment instructions

**Documentation for AI agents:**
- [`docs/AI_SETUP_GUIDE.md`](docs/AI_SETUP_GUIDE.md) - Complete setup instructions
- [`docs/AI_QUESTIONNAIRE.md`](docs/AI_QUESTIONNAIRE.md) - Information gathering checklist

---

### Method 2: Interactive Wizard

Run the built-in wizard to generate configuration interactively:

```bash
# Install the package first
composer require deployer/deployer:^8.0@alpha cothinking-dev/deployer-laravel-stack:^2.0 --dev

# Run the wizard
vendor/bin/dep init
```

The wizard will prompt you for:
- Application name and repository
- Server hostname
- Database type (PostgreSQL, MySQL, SQLite)
- Secrets management (1Password or plain .env)
- Web server mode (PHP-FPM or Octane)

Then deploy:

```bash
./deploy/dep setup:server server      # Bootstrap server + GitHub deploy key
./deploy/dep setup:environment prod   # Provision + deploy
```

---

### Method 3: Manual Configuration

For full control, copy and edit the example files:

#### Step 1: Copy Example Files

```bash
cp vendor/cothinking-dev/deployer-laravel-stack/examples/deploy.php ./deploy.php
mkdir -p deploy
cp vendor/cothinking-dev/deployer-laravel-stack/examples/deploy/dep ./deploy/dep
cp vendor/cothinking-dev/deployer-laravel-stack/examples/deploy/secrets.tpl ./deploy/secrets.tpl
chmod +x ./deploy/dep
```

#### Step 2: Edit `deploy.php`

Update these values:

```php
set('application', 'Your Application Name');
set('repository', 'git@github.com:your-org/your-repo.git');
set('server_hostname', getenv('DEPLOYER_HOST') ?: 'your-server.example.com');

// Database (choose one)
set('db_connection', 'sqlite');  // default, or 'pgsql' or 'mysql'

// Web server (choose one)
set('web_server', 'fpm');  // or 'octane'

// Environments
environment('prod', [
    'deploy_path' => '/home/deployer/your-app',
    'domain' => 'your-domain.com',
    'db_name' => 'your_app',
    'redis_db' => 0,
]);
```

#### Step 3: Configure Secrets

**Option A: 1Password (recommended)**

Edit `deploy/secrets.tpl`:
```bash
DEPLOYER_SUDO_PASS=op://YourVault/your-item/sudo-password
DEPLOYER_DB_PASSWORD=op://YourVault/your-item/db-password
DEPLOYER_APP_KEY=op://YourVault/your-item/app-key
```

**Option B: Plain .env file**

Create `deploy/secrets.env`:
```bash
DEPLOYER_SUDO_PASS=your-sudo-password
DEPLOYER_DB_PASSWORD=your-db-password
DEPLOYER_APP_KEY=base64:your-laravel-key
```

Add to `.gitignore`:
```bash
echo "deploy/secrets.env" >> .gitignore
```

#### Step 4: Deploy

```bash
./deploy/dep setup:server server      # Bootstrap + GitHub deploy key
./deploy/dep setup:environment prod   # Provision + configure + deploy
```

---

## Post-Setup: Migrating Existing Data

If you have existing data (SQLite database, media files) to migrate:

```bash
./deploy/dep data:migrate prod
```

This interactive wizard will:
- Upload SQLite databases (if using SQLite)
- Upload media directories (configured in `storage_links`)
- Set correct permissions

---

## File Structure

After setup, your project will have:

```
your-project/
├── deploy.php              # Main deployer configuration
├── deploy/
│   ├── dep                 # Wrapper script with project config
│   └── secrets.tpl         # 1Password references (or secrets.env)
└── docs/                   # AI agent documentation (optional)
    ├── AI_SETUP_GUIDE.md
    └── AI_QUESTIONNAIRE.md
```

---

## Configuration Reference

### Database Configuration

```php
// SQLite (default) - zero config, perfect for most Laravel apps
set('db_connection', 'sqlite');
// Database auto-created at: {{deploy_path}}/shared/database/database.sqlite

// PostgreSQL - for high-traffic production
set('db_connection', 'pgsql');
set('db_username', 'deployer');

// MySQL
set('db_connection', 'mysql');
set('db_username', 'deployer');
```

See [DATABASE_CONFIGURATION.md](DATABASE_CONFIGURATION.md) for detailed database setup.

### Web Server Configuration

```php
// Traditional PHP-FPM (default)
set('web_server', 'fpm');

// Laravel Octane with FrankenPHP
set('web_server', 'octane');
set('octane_port', 8000);
```

### Storage Links (for user uploads)

```php
// Link public/media to shared/media
set('storage_links', [
    'media' => 'media',
    'uploads' => 'uploads',
]);
```

### Environment Helper

```php
environment('prod', [
    'deploy_path' => '/home/deployer/myapp',  // Required
    'domain'      => 'myapp.com',             // Required
    'db_name'     => 'myapp',                 // Required
    'redis_db'    => 0,                       // Required
    'app_debug'   => false,                   // Default: false
    'log_level'   => 'error',                 // Default: 'error'
    'tls_mode'    => 'internal',              // Default: 'internal'
    'env'         => [],                      // Per-environment .env overrides
]);
```

---

## Common Commands

### Deployment

```bash
./deploy/dep deploy prod          # Deploy to production
./deploy/dep deploy staging       # Deploy to staging
./deploy/dep deploy:all           # Deploy to all environments
./deploy/dep rollback prod        # Rollback to previous release
```

### Server Management

```bash
./deploy/dep setup:server server      # Bootstrap server (run once)
./deploy/dep setup:environment prod   # Provision + deploy environment
./deploy/dep provision:all prod       # Re-run provisioning tasks
```

### Diagnostics

```bash
./deploy/dep artisan:log prod     # View Laravel logs
./deploy/dep caddy:status prod    # Check Caddy status
./deploy/dep deploy:verify prod   # Run health check
./deploy/dep ssh prod             # SSH into server
```

### Database

```bash
./deploy/dep db:backup prod       # Create database backup
./deploy/dep db:backups prod      # List backups
./deploy/dep db:restore prod      # Restore from backup
```

### Data Migration

```bash
./deploy/dep data:migrate prod    # Interactive data migration wizard
```

---

## Multi-Environment Setup

Production and staging are separate deployments on the same server:

| Setting | Production | Staging |
|---------|------------|---------|
| Deploy path | `/home/deployer/myapp` | `/home/deployer/myapp-staging` |
| Database | `myapp` | `myapp_staging` |
| Redis DB | `0` | `1` |
| Domain | `myapp.com` | `staging.myapp.com` |

```bash
./deploy/dep setup:environment prod     # First environment (provisions server)
./deploy/dep setup:environment staging  # Second environment (fast, reuses provisioning)
```

---

## Multiple Projects on One Server

Each project maintains its own configuration. Coordinate these resources:

| Resource | Project A | Project B |
|----------|-----------|-----------|
| Deploy path | `/home/deployer/project-a` | `/home/deployer/project-b` |
| Database | `project_a` | `project_b` |
| Redis DB | `0`, `1` | `2`, `3` |

Deploy keys are project-specific (GitHub requires unique keys per repo):

```bash
# From Project A directory
./deploy/dep github:generate-key server
./deploy/dep github:deploy-key server

# From Project B directory
./deploy/dep github:generate-key server
./deploy/dep github:deploy-key server
```

---

## Security Features

- PostgreSQL and Redis listen on localhost only
- UFW firewall allows only SSH (rate-limited), HTTP, HTTPS
- Fail2ban protects against brute force attacks
- Deployer user has restricted sudo access (whitelisted commands only)
- No secrets stored on disk or in git (when using 1Password)

---

## GitHub Actions CI/CD

Deploy automatically via GitHub Actions instead of manual `./deploy/dep` commands.

### Setup

1. **Generate CI/CD SSH key on server:**

   ```bash
   ./deploy/dep github:ci-key server
   ```

   Copy the private key output (displayed once).

2. **Create GitHub environments:**

   Go to Repository Settings → Environments → New environment
   - Create `staging` environment
   - Create `production` environment (optionally add protection rules)

3. **Set secrets for each environment:**

   ```bash
   # SSH key (from github:ci-key output)
   gh secret set SSH_PRIVATE_KEY --env staging < /path/to/key
   gh secret set SSH_PRIVATE_KEY --env production < /path/to/key

   # Required secrets
   gh secret set DEPLOYER_SUDO_PASS --env staging
   gh secret set DEPLOYER_SUDO_PASS --env production
   gh secret set DEPLOYER_APP_KEY --env staging    # Different key per env!
   gh secret set DEPLOYER_APP_KEY --env production

   # Database password (if not using SQLite)
   gh secret set DEPLOYER_DB_PASSWORD --env staging
   gh secret set DEPLOYER_DB_PASSWORD --env production
   ```

4. **Copy workflow file:**

   ```bash
   mkdir -p .github/workflows
   cp vendor/cothinking-dev/deployer-laravel-stack/examples/.github/workflows/deploy.yml .github/workflows/
   ```

5. **Push and deploy:**

   ```bash
   git add .github/workflows/deploy.yml
   git commit -m "Add GitHub Actions CI/CD"
   git push origin main  # Triggers production deploy
   ```

### Branch Workflow

| Branch | Deploys To |
|--------|------------|
| `main` | Production |
| `develop` | Staging |
| Manual trigger | Choose environment |

### Manual Trigger

```bash
# Via GitHub CLI
gh workflow run deploy -f environment=staging
gh workflow run deploy -f environment=production

# Or via GitHub web UI: Actions → Deploy → Run workflow
```

### Hetzner Cloud Firewall (Optional)

If using Hetzner Cloud, whitelist GitHub Actions IP ranges:

```bash
# Requires: hcloud CLI installed and configured
./deploy/dep hcloud:github-ips server

# Refresh IPs periodically (GitHub IPs change)
./deploy/dep hcloud:github-ips:refresh server
```

### Secrets Modes

The `bin/dep` wrapper auto-detects secrets mode:

| Mode | When Used | Configuration |
|------|-----------|---------------|
| `env-vars` | CI/CD | `DEPLOYER_*` env vars set directly |
| `1password` | Local dev | `deploy/secrets.tpl` exists |
| `env-file` | Handover | `deploy/secrets.env` exists |

Override with `--secrets-mode=MODE` flag.

---

## Troubleshooting

### Deploy key not working

```bash
./deploy/dep github:deploy-key server   # Re-add deploy key
```

### Health check failing

```bash
./deploy/dep artisan:log prod           # Check Laravel logs
./deploy/dep caddy:status prod          # Check Caddy status
curl -I https://your-domain.com         # Manual check
```

### Database connection failed

```bash
./deploy/dep db:check prod              # Test connection
./deploy/dep env:show prod              # Verify credentials
```

### Permission denied (sudo)

```bash
# Re-run bootstrap as root
./deploy/dep provision:bootstrap server
```

### Mixed Content / Broken Filament Forms

If your Filament admin forms are broken (select, file-upload, markdown editor not working) or you see "Mixed Content" errors in the browser console:

```bash
# Check HTTPS configuration
./deploy/dep check:https-config prod
./deploy/dep verify:https-all prod
```

**Common causes:**
1. Missing `URL::forceScheme('https')` in AppServiceProvider
2. Missing TrustProxies middleware
3. `APP_URL` uses `http://` instead of `https://`

See [HTTPS & Proxy Guide](docs/HTTPS_PROXY_GUIDE.md) for detailed solutions.

### Storage Files Return 404

```bash
# Check storage symlink
./deploy/dep verify:storage-symlink prod
./deploy/dep verify:storage-files prod

# Fix if pointing to wrong location
./deploy/dep storage:link-custom prod
```

**Cause:** `php artisan storage:link` creates a symlink to the release storage instead of shared storage. The recipe handles this automatically, but if you ran artisan manually it can break.

### Filament Assets Missing

```bash
# Check assets exist
./deploy/dep check:filament-assets prod

# Generate assets locally, then redeploy
php artisan filament:assets
git add public/js/filament public/css/filament
git commit -m "Add Filament assets"
git push
./deploy/dep deploy prod
```

---

## Documentation

- [Database Configuration](DATABASE_CONFIGURATION.md) - PostgreSQL, MySQL, SQLite setup
- [HTTPS & Proxy Guide](docs/HTTPS_PROXY_GUIDE.md) - Fixing mixed content and Filament issues
- [AI Setup Guide](docs/AI_SETUP_GUIDE.md) - Instructions for AI agents
- [AI Questionnaire](docs/AI_QUESTIONNAIRE.md) - Information gathering checklist
- [Deployment Flow](docs/DEPLOYMENT_FLOW.md) - Task execution order

---

## License

MIT
