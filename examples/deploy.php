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
// Database
// ─────────────────────────────────────────────────────────────────────────────

// Uncomment to create additional databases during provisioning:
// set('additional_databases', ['myapp_staging']);

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
    'domain' => 'myapp.example.com',
    'db_name' => 'myapp',
    'redis_db' => 0,
    'env' => [
        'GTM_ID' => 'GTM-XXXXXXX',
    ],
]);

environment('staging', [
    'deploy_path' => '~/myapp-staging',
    'domain' => 'staging.myapp.example.com',
    'db_name' => 'myapp_staging',
    'redis_db' => 1,
    'app_debug' => true,
    'log_level' => 'debug',
    'env' => [
        'GTM_ID' => '',
    ],
]);

// ─────────────────────────────────────────────────────────────────────────────
// Shared Environment Variables
// ─────────────────────────────────────────────────────────────────────────────

set('shared_env', [
    // Filesystem
    'FILESYSTEM_DISK' => 'local',

    // Mail
    'MAIL_MAILER' => 'smtp',
    'MAIL_FROM_ADDRESS' => 'hello@example.com',
    'MAIL_FROM_NAME' => '${APP_NAME}',

    // Third-party services (use {secret_key} for secrets)
    'STRIPE_KEY' => '{stripe_key}',
]);

// ─────────────────────────────────────────────────────────────────────────────
// Queue Workers (optional)
// ─────────────────────────────────────────────────────────────────────────────

// Uncomment to enable Supervisor-managed queue workers:
// set('queue_worker_name', fn () => 'myapp-' . getStage() . '-worker');

// ─────────────────────────────────────────────────────────────────────────────
// Hooks
// ─────────────────────────────────────────────────────────────────────────────

after('deploy:failed', 'deploy:unlock');
