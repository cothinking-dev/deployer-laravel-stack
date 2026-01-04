<?php

namespace Deployer;

require __DIR__ . '/helpers.php';
require __DIR__ . '/tasks/env.php';
require __DIR__ . '/tasks/npm.php';
require __DIR__ . '/tasks/services.php';
require __DIR__ . '/provision/bootstrap.php';
require __DIR__ . '/provision/firewall.php';
require __DIR__ . '/provision/php.php';
require __DIR__ . '/provision/composer.php';
require __DIR__ . '/provision/node.php';
require __DIR__ . '/provision/postgres.php';
require __DIR__ . '/provision/redis.php';
require __DIR__ . '/provision/caddy.php';
require __DIR__ . '/provision/fail2ban.php';

set('php_version', '8.4');
set('node_version', '22');
set('db_username', 'deployer');

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

// Ensure home directory is traversable by web servers
desc('Ensure home directory is traversable');
task('deploy:fix-permissions', function () {
    run('chmod 755 $HOME');
});

before('deploy:shared', 'deploy:env');
before('deploy:symlink', 'deploy:fix-permissions');
after('deploy:vendors', 'npm:install');
after('npm:install', 'npm:build');
after('deploy:symlink', 'php-fpm:restart');
