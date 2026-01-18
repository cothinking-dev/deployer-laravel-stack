<?php

/**
 * Deployer Configuration for Laravel Applications
 *
 * This file configures zero-downtime deployments with automated provisioning.
 *
 * Setup methods:
 *   1. AI-Assisted:  Ask your AI to "Read docs/AI_SETUP_GUIDE.md and configure deployment"
 *   2. Wizard:       Run `vendor/bin/dep init` for interactive setup
 *   3. Manual:       Edit this file directly (see comments below)
 *
 * Quick start after configuration:
 *   ./deploy/dep setup:server server       # Bootstrap server (once)
 *   ./deploy/dep setup:environment prod    # Provision + deploy
 */

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/cothinking-dev/deployer-laravel-stack/src/recipe.php';

// ─────────────────────────────────────────────────────────────────────────────
// APPLICATION [REQUIRED]
// ─────────────────────────────────────────────────────────────────────────────

set('application', 'My Application');                              // Your app name
set('repository', 'git@github.com:your-org/your-repo.git');        // Git SSH URL
set('keep_releases', 5);                                           // Releases to keep

// ─────────────────────────────────────────────────────────────────────────────
// SERVER [REQUIRED]
// ─────────────────────────────────────────────────────────────────────────────

set('server_hostname', getenv('DEPLOYER_HOST') ?: 'your-server.example.com');

host('server')
    ->setHostname(get('server_hostname'))
    ->set('remote_user', 'root')
    ->set('labels', ['stage' => 'server'])
    ->set('deploy_path', '/home/deployer/myapp');

// ─────────────────────────────────────────────────────────────────────────────
// STACK CONFIGURATION [REQUIRED]
// ─────────────────────────────────────────────────────────────────────────────

// PHP and Node versions
set('php_version', '8.4');    // Options: '8.2', '8.3', '8.4'
set('node_version', '22');    // Options: '18', '20', '22'

// Web server mode
set('web_server', 'fpm');     // Options: 'fpm' (PHP-FPM + Caddy) or 'octane' (Laravel Octane)

// If using Octane, uncomment these:
// set('octane_port', 8000);
// set('octane_admin_port', 2019);

// ─────────────────────────────────────────────────────────────────────────────
// DATABASE [REQUIRED - Choose ONE]
// ─────────────────────────────────────────────────────────────────────────────
// Available options: 'sqlite' (default), 'pgsql', 'mysql'
// You can also use constants: DbConnection::SQLITE, DbConnection::PGSQL, DbConnection::MYSQL

// Option 1: SQLite (default) - zero config, perfect for most Laravel apps
// Recommended for new projects, development, and small-to-medium production apps
set('db_connection', 'sqlite');
// Database auto-created at: {{deploy_path}}/shared/database/database.sqlite

// Option 2: PostgreSQL - for high-traffic production apps requiring advanced features
// set('db_connection', 'pgsql');
// set('db_name', 'myapp');
// set('db_username', 'deployer');

// Option 3: MySQL - alternative to PostgreSQL
// set('db_connection', 'mysql');
// set('db_name', 'myapp');
// set('db_username', 'deployer');

// ─────────────────────────────────────────────────────────────────────────────
// SECRETS [REQUIRED]
// ─────────────────────────────────────────────────────────────────────────────

// Secrets are loaded from deploy/secrets.tpl (1Password) or deploy/secrets.env
set('secrets', fn () => requireSecrets(
    required: [
        'DEPLOYER_SUDO_PASS',      // Server sudo password
        'DEPLOYER_APP_KEY',        // Laravel APP_KEY
    ],
    optional: [
        'DEPLOYER_DB_PASSWORD' => '',  // Required for PostgreSQL/MySQL, not needed for SQLite
        'DEPLOYER_STRIPE_KEY' => '',   // Example optional secret
    ]
));

// ─────────────────────────────────────────────────────────────────────────────
// ENVIRONMENTS [REQUIRED]
// ─────────────────────────────────────────────────────────────────────────────

environment('prod', [
    'deploy_path' => '/home/deployer/myapp',
    'domain' => 'myapp.example.com',
    'db_name' => 'myapp',
    'redis_db' => 0,
    'env' => [
        // Production-specific env vars
        'GTM_ID' => 'GTM-XXXXXXX',
    ],
]);

environment('staging', [
    'deploy_path' => '/home/deployer/myapp-staging',
    'domain' => 'staging.myapp.example.com',
    'db_name' => 'myapp_staging',
    'redis_db' => 1,
    'app_debug' => true,
    'log_level' => 'debug',
    'env' => [
        // Staging-specific env vars
        'GTM_ID' => '',
    ],
]);

// ─────────────────────────────────────────────────────────────────────────────
// SHARED ENVIRONMENT VARIABLES [OPTIONAL]
// ─────────────────────────────────────────────────────────────────────────────

set('shared_env', [
    // Filesystem
    'FILESYSTEM_DISK' => 'local',

    // Mail (configure as needed)
    'MAIL_MAILER' => 'smtp',
    'MAIL_FROM_ADDRESS' => 'hello@example.com',
    'MAIL_FROM_NAME' => '${APP_NAME}',

    // Third-party services - use {secret_key} to reference secrets
    // 'STRIPE_KEY' => '{stripe_key}',
]);

// ─────────────────────────────────────────────────────────────────────────────
// STORAGE LINKS [OPTIONAL]
// ─────────────────────────────────────────────────────────────────────────────

// If your app has user uploads, configure symlinks from public/ to shared/
// set('storage_links', [
//     'media' => 'media',       // public/media → shared/media
//     'uploads' => 'uploads',   // public/uploads → shared/uploads
// ]);

// ─────────────────────────────────────────────────────────────────────────────
// QUEUE WORKERS [OPTIONAL]
// ─────────────────────────────────────────────────────────────────────────────

// Uncomment to enable Supervisor-managed queue workers:
// set('queue_worker_name', fn () => 'myapp-' . getStage() . '-worker');

// ─────────────────────────────────────────────────────────────────────────────
// HOOKS [OPTIONAL]
// ─────────────────────────────────────────────────────────────────────────────

after('deploy:failed', 'deploy:unlock');

// Add custom hooks here:
// after('deploy:symlink', 'artisan:custom-command');
