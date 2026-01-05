<?php

namespace Deployer;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/tasks/env.php';
require __DIR__.'/tasks/npm.php';
require __DIR__.'/tasks/services.php';
require __DIR__.'/tasks/github.php';
require __DIR__.'/tasks/verify.php';
require __DIR__.'/tasks/storage.php';
require __DIR__.'/tasks/preflight.php';
require __DIR__.'/tasks/rollback.php';
require __DIR__.'/tasks/migrate.php';
require __DIR__.'/tasks/cache.php';
require __DIR__.'/provision/bootstrap.php';
require __DIR__.'/provision/firewall.php';
require __DIR__.'/provision/php.php';
require __DIR__.'/provision/composer.php';
require __DIR__.'/provision/node.php';
require __DIR__.'/provision/postgres.php';
require __DIR__.'/provision/redis.php';
require __DIR__.'/provision/caddy.php';
require __DIR__.'/provision/fail2ban.php';

set('php_version', '8.4');
set('node_version', '22');
set('db_username', 'deployer');

// SSH Connection Hardening (improves stability with Deployer v8's socket server)
set('default_timeout', 300);
set('ssh_arguments', [
    '-o', 'ConnectTimeout=30',
    '-o', 'ServerAliveInterval=15',
    '-o', 'ServerAliveCountMax=4',
    '-o', 'TCPKeepAlive=yes',
]);

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
after('deploy:vendors', 'npm:install');
after('npm:install', 'npm:build');

// Fix PostgreSQL sequences before migrations to prevent duplicate key errors
after('npm:build', 'db:fix-sequences');

// Run migrations with backup before going live
after('db:fix-sequences', 'migrate:safe');

after('deploy:symlink', 'php-fpm:restart');
after('deploy:symlink', 'artisan:up');

// Clear and rebuild caches after app is live
after('artisan:up', 'artisan:cache:refresh');

after('artisan:storage:link', 'storage:link-custom');

// Verify deployment health - triggers auto-rollback on failure
after('deploy:symlink', 'deploy:verify');
after('deploy:symlink', 'queue:restart');

// On failure, attempt to rollback and unlock
fail('deploy', 'deploy:rollback-on-failure');
fail('deploy', 'deploy:unlock');
