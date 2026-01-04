<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/cothinking-dev/deployer-laravel-stack/src/recipe.php';

set('application', 'My Application');
set('repository', 'git@github.com:your-org/your-repo.git');
set('keep_releases', 5);

set('ssh_multiplexing', true);
set('ssh_arguments', [
    '-o ControlMaster=auto',
    '-o ControlPersist=10',
]);

add('shared_files', ['.env']);
add('shared_dirs', ['storage']);
add('writable_dirs', ['storage', 'bootstrap/cache']);

set('secrets', function () {
    $required = ['DEPLOYER_SUDO_PASS', 'DEPLOYER_DB_PASSWORD', 'DEPLOYER_APP_KEY'];
    $missing = array_filter($required, fn ($var) => ! getenv($var));

    if (! empty($missing)) {
        throw new \RuntimeException(
            'Missing required secrets: ' . implode(', ', $missing) . "\n" .
            'Run deployment via: ./deploy/dep <command> <environment>'
        );
    }

    return [
        'sudo_pass' => getenv('DEPLOYER_SUDO_PASS'),
        'db_password' => getenv('DEPLOYER_DB_PASSWORD'),
        'app_key' => getenv('DEPLOYER_APP_KEY'),
    ];
});

$serverHostname = getenv('DEPLOYER_HOST') ?: 'your-server.example.com';

host('server')
    ->setHostname($serverHostname)
    ->set('remote_user', 'root')
    ->set('labels', ['stage' => 'server'])
    ->set('deploy_path', '~/myapp');

$defaults = [
    'tls_mode' => 'internal',
    'app_debug' => 'false',
    'log_level' => 'error',
];

$environments = [
    'prod' => [
        'deploy_path' => '~/myapp',
        'branch' => 'main',
        'domain' => 'myapp.example.com',
        'db_name' => 'myapp',
        'redis_db' => '0',
        'app_env' => 'production',
    ],
    'staging' => [
        'deploy_path' => '~/myapp-staging',
        'branch' => 'staging',
        'domain' => 'staging.myapp.example.com',
        'db_name' => 'myapp_staging',
        'redis_db' => '1',
        'app_env' => 'staging',
        'app_debug' => 'true',
        'log_level' => 'debug',
    ],
];

foreach ($environments as $name => $config) {
    $merged = array_merge($defaults, $config);

    host($name)
        ->setHostname($serverHostname)
        ->set('remote_user', 'deployer')
        ->set('forward_agent', false)
        ->set('labels', ['stage' => $name])
        ->set('url', "https://{$merged['domain']}")
        ->set('deploy_path', $merged['deploy_path'])
        ->set('branch', $merged['branch'])
        ->set('domain', $merged['domain'])
        ->set('db_name', $merged['db_name'])
        ->set('redis_db', $merged['redis_db'])
        ->set('app_env', $merged['app_env'])
        ->set('app_debug', $merged['app_debug'])
        ->set('log_level', $merged['log_level'])
        ->set('tls_mode', $merged['tls_mode']);
}

set('env_extras', function () {
    $configFile = __DIR__ . '/deploy/config/env-extras.php';

    if (! file_exists($configFile)) {
        return [];
    }

    $config = require $configFile;
    $stage = getStage();
    $secrets = get('secrets');

    $extras = array_merge(
        $config['common'] ?? [],
        $config[$stage] ?? []
    );

    return array_map(function ($value) use ($secrets) {
        if (is_string($value) && preg_match('/^\{(\w+)\}$/', $value, $matches)) {
            return $secrets[$matches[1]] ?? $value;
        }

        return $value;
    }, $extras);
});

desc('Add server deploy key to GitHub via gh CLI');
task('github:deploy-key', function () {
    $repo = get('repository');

    if (! preg_match('#git@github\.com:(.+?)\.git#', $repo, $matches)) {
        warning('Could not parse GitHub repository from: ' . $repo);

        return;
    }

    $repoPath = $matches[1];
    $hostname = run('hostname');
    $keyTitle = "deployer@{$hostname}";

    $pubKey = run('cat ~/.ssh/id_ed25519.pub 2>/dev/null || echo ""');

    if (empty(trim($pubKey))) {
        warning('No deploy key found on server');

        return;
    }

    info("Adding deploy key to {$repoPath}...");

    $existingKeys = runLocally("gh repo deploy-key list -R {$repoPath} 2>/dev/null || echo ''");

    if (str_contains($existingKeys, $keyTitle)) {
        info("Deploy key '{$keyTitle}' already exists, skipping");

        return;
    }

    $escapedKey = escapeshellarg(trim($pubKey));
    $result = runLocally("echo {$escapedKey} | gh repo deploy-key add - --title '{$keyTitle}' -R {$repoPath} 2>&1 || echo 'FAILED'");

    if (str_contains($result, 'FAILED') || str_contains($result, 'error')) {
        warning("Failed to add deploy key: {$result}");
        warning('You may need to add it manually or check gh auth status');

        return;
    }

    info("Deploy key added to GitHub: {$keyTitle}");
})->once();

desc('Verify deployment health');
task('deploy:verify', function () {
    $url = get('url');
    $stage = getStage();

    info("Verifying deployment for {$stage}...");

    run('sleep 2');

    $checkUrl = rtrim($url, '/');
    $result = runLocally("curl -s -o /dev/null -w '%{http_code}' -L --max-time 15 --insecure {$checkUrl} 2>/dev/null || echo '000'");
    $statusCode = trim($result);

    if (! in_array($statusCode, ['200', '301', '302', '303', '307', '308'])) {
        warning("Health check returned HTTP {$statusCode}");
        run('tail -20 {{deploy_path}}/shared/storage/logs/laravel.log 2>/dev/null || true');

        throw new \RuntimeException("Deployment verification failed: HTTP {$statusCode}");
    }

    info("Health check passed (HTTP {$statusCode})");
});

desc('Setup server from scratch (bootstrap + add deploy key)');
task('setup:server', [
    'provision:bootstrap',
    'github:deploy-key',
])->oncePerNode();

desc('Provision and deploy an environment');
task('setup:environment', [
    'provision:all',
    'caddy:configure',
    'deploy',
]);

after('deploy:failed', 'deploy:unlock');
after('deploy:symlink', 'deploy:verify');

fail('deploy', 'deploy:unlock');
