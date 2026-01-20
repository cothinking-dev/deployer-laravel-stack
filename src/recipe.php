<?php

namespace Deployer;

require_once __DIR__.'/constants.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/tasks/env.php';
require __DIR__.'/tasks/npm.php';
require __DIR__.'/tasks/services.php';
require __DIR__.'/tasks/github.php';
require __DIR__.'/tasks/hcloud.php';
require __DIR__.'/tasks/verify.php';
require __DIR__.'/tasks/https.php';
require __DIR__.'/tasks/storage.php';
require __DIR__.'/tasks/preflight.php';
require __DIR__.'/tasks/rollback.php';
require __DIR__.'/tasks/migrate.php';
require __DIR__.'/tasks/cache.php';
require __DIR__.'/tasks/init.php';
require __DIR__.'/tasks/data-migrate.php';
require __DIR__.'/provision/bootstrap.php';
require __DIR__.'/provision/firewall.php';
require __DIR__.'/provision/php.php';
require __DIR__.'/provision/composer.php';
require __DIR__.'/provision/node.php';
require __DIR__.'/provision/postgres.php';
require __DIR__.'/provision/mysql.php';
require __DIR__.'/provision/sqlite.php';
require __DIR__.'/provision/database.php';
require __DIR__.'/provision/redis.php';
require __DIR__.'/provision/caddy.php';
require __DIR__.'/provision/octane.php';
require __DIR__.'/provision/fail2ban.php';

set('php_version', '8.4');
set('node_version', '22');
set('db_connection', DbConnection::DEFAULT);  // 'sqlite' - zero config, perfect for most Laravel apps
set('db_username', 'deployer');

// Web server mode: 'fpm' (PHP-FPM) or 'octane' (Laravel Octane with FrankenPHP)
set('web_server', WebServer::DEFAULT);  // 'fpm'

// Default timeout for commands (5 minutes)
set('default_timeout', 300);

// Use ACL for writable directories - works with shared dirs owned by different users (www-data)
// This avoids chmod permission errors on symlinked shared directories
set('writable_mode', 'acl');
set('writable_use_sudo', false);

desc('Run all provisioning tasks (new server setup)');
task('provision:all', [
    'provision:firewall',
    'provision:fail2ban',
    'provision:php',
    'provision:composer',
    'provision:node',
    'provision:redis',
    'provision:postgres',
    'provision:caddy',
]);

desc('Provision application stack without web server');
task('provision:stack', [
    'provision:php',
    'provision:composer',
    'provision:node',
    'provision:redis',
    'provision:postgres',
]);

desc('Ensure home directory is traversable');
task('deploy:fix-permissions', function () {
    run('chmod 755 $HOME');
});

desc('Setup server from scratch (bootstrap + generate project deploy key + add to GitHub)');
task('setup:server', [
    'provision:bootstrap',
    'github:generate-key',
    'github:deploy-key',
])->oncePerNode();

desc('Provision and deploy an environment');
task('setup:environment', [
    'deploy:unlock',
    'provision:all',
    'caddy:configure',
    'deploy',
]);

desc('Setup all environments (provision:all + caddy for prod & staging + deploy all)');
task('setup:all', function () {
    info('Setting up all environments...');
})->oncePerNode();

after('setup:all', 'provision:all');

// Run pre-flight checks before anything else
before('deploy:prepare', 'deploy:preflight');

before('deploy:shared', 'deploy:env');
before('deploy:symlink', 'deploy:fix-permissions');
before('deploy:symlink', 'artisan:down');
before('deploy:symlink', 'horizon:terminate');
// Rebuild config cache immediately after vendors to ensure fresh .env values
// This prevents stale cached config from previous releases affecting database operations
after('deploy:vendors', 'artisan:config:fresh');

after('artisan:config:fresh', 'npm:install');
after('npm:install', 'npm:build');

// Fix PostgreSQL sequences before migrations to prevent duplicate key errors
after('npm:build', 'db:fix-sequences');

// Ensure SQLite database file exists before running migrations
after('db:fix-sequences', 'db:ensure-sqlite');

// Run migrations with backup before going live
after('db:ensure-sqlite', 'migrate:safe');

// Reload web server (PHP-FPM or Octane depending on web_server config)
desc('Reload web server for new code');
task('webserver:reload', function () {
    $webServer = get('web_server', WebServer::DEFAULT);

    if ($webServer === WebServer::OCTANE) {
        // Use the safe reload that checks if Octane is actually running
        invoke('octane:reload:if-running');
    } else {
        invoke('php-fpm:restart');
    }
});

after('deploy:symlink', 'webserver:reload');
after('deploy:symlink', 'artisan:up');

// Clear and rebuild caches after app is live
after('artisan:up', 'artisan:cache:refresh');

after('artisan:storage:link', 'storage:link-custom');

// Verify deployment health - triggers auto-rollback on failure
after('deploy:symlink', 'deploy:verify');
after('deploy:symlink', 'queue:restart');

// HTTPS and asset verification (runs after web server reload for accurate results)
after('webserver:reload', 'verify:https-redirects');
after('webserver:reload', 'verify:storage-symlink');

// On failure, attempt to rollback and unlock
fail('deploy', 'deploy:rollback-on-failure');
fail('deploy', 'deploy:unlock');
