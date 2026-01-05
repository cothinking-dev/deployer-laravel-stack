<?php

namespace Deployer;

desc('Restart PHP-FPM');
task('php-fpm:restart', function () {
    $version = get('php_version', '8.4');

    sudo("systemctl restart php{$version}-fpm");

    // Wait for FPM to be ready
    $maxAttempts = 10;
    $attempt = 0;

    while ($attempt < $maxAttempts) {
        $status = run("systemctl is-active php{$version}-fpm 2>/dev/null || echo 'inactive'");

        if (trim($status) === 'active') {
            info("Restarted PHP {$version}-FPM (ready after " . ($attempt + 1) . " check(s))");

            return;
        }

        $attempt++;
        run('sleep 0.5');
    }

    throw new \RuntimeException("PHP {$version}-FPM failed to become active after restart");
});

desc('Show PHP-FPM status');
task('php-fpm:status', function () {
    $version = get('php_version', '8.4');
    $status = sudo("systemctl status php{$version}-fpm --no-pager -l");
    writeln($status);
});

desc('Restart queue workers');
task('artisan:queue:restart', function () {
    run('cd {{release_path}} && {{bin/php}} artisan queue:restart');
    info('Queue workers will restart after current job completes');
});

desc('Clear all Laravel caches');
task('artisan:cache:clear', function () {
    run('cd {{release_path}} && {{bin/php}} artisan cache:clear');
    run('cd {{release_path}} && {{bin/php}} artisan config:clear');
    run('cd {{release_path}} && {{bin/php}} artisan route:clear');
    run('cd {{release_path}} && {{bin/php}} artisan view:clear');
    info('All caches cleared');
});

desc('Optimize Laravel (cache config, routes, views)');
task('artisan:optimize', function () {
    run('cd {{release_path}} && {{bin/php}} artisan config:cache');
    run('cd {{release_path}} && {{bin/php}} artisan route:cache');
    run('cd {{release_path}} && {{bin/php}} artisan view:cache');
    info('Laravel optimized');
});

desc('Show last 50 lines of Laravel log');
task('artisan:log', function () {
    run('tail -50 {{deploy_path}}/current/storage/logs/laravel.log 2>/dev/null || echo "No log file found"');
});

desc('Show last 50 lines of Laravel log (follow mode)');
task('artisan:log:follow', function () {
    run('tail -f {{deploy_path}}/current/storage/logs/laravel.log');
});

desc('Show application status');
task('app:status', function () {
    writeln('');
    writeln('Current Release:');
    run('ls -la {{deploy_path}}/current');

    writeln('');
    writeln('Releases:');
    run('ls -la {{deploy_path}}/releases');

    writeln('');
    writeln('Disk Usage:');
    run('df -h {{deploy_path}}');
});

set('maintenance_mode', true);

desc('Put application in maintenance mode');
task('artisan:down', function () {
    if (! get('maintenance_mode', true)) {
        return;
    }

    if (! test('[ -f {{deploy_path}}/current/artisan ]')) {
        return;
    }

    $secret = get('maintenance_secret', bin2hex(random_bytes(16)));
    run("cd {{deploy_path}}/current && {{bin/php}} artisan down --secret={$secret} --retry=60");

    // Verify maintenance mode is active
    if (test('[ -f {{deploy_path}}/current/storage/framework/down ]')) {
        info("Maintenance mode enabled (secret: {$secret})");
    } else {
        warning('Maintenance mode file not found - mode may not be active');
    }
});

desc('Bring application out of maintenance mode');
task('artisan:up', function () {
    if (! get('maintenance_mode', true)) {
        return;
    }

    run('cd {{release_path}} && {{bin/php}} artisan up');
    info('Application is now live');
});

set('horizon_enabled', false);

desc('Terminate Horizon workers gracefully');
task('horizon:terminate', function () {
    if (! get('horizon_enabled', false)) {
        return;
    }

    if (! test('[ -f {{deploy_path}}/current/artisan ]')) {
        info('No current release found, skipping Horizon termination');

        return;
    }

    try {
        $result = run('cd {{deploy_path}}/current && {{bin/php}} artisan horizon:terminate 2>&1');
        info('Horizon workers terminating...');

        if (str_contains($result, 'error') || str_contains($result, 'Exception')) {
            warning("Horizon terminate returned: {$result}");
        }
    } catch (\Throwable $e) {
        // Horizon might not be running, which is okay
        if (str_contains($e->getMessage(), 'not running') || str_contains($e->getMessage(), 'Connection refused')) {
            info('Horizon not running, skipping termination');
        } else {
            warning('Horizon terminate failed: ' . $e->getMessage());
        }
    }
});

desc('Check Horizon status');
task('horizon:status', function () {
    $status = run('cd {{deploy_path}}/current && {{bin/php}} artisan horizon:status 2>/dev/null || echo "Horizon not running"');
    writeln($status);
});

desc('Pause Horizon processing');
task('horizon:pause', function () {
    run('cd {{deploy_path}}/current && {{bin/php}} artisan horizon:pause');
    info('Horizon paused');
});

desc('Resume Horizon processing');
task('horizon:continue', function () {
    run('cd {{deploy_path}}/current && {{bin/php}} artisan horizon:continue');
    info('Horizon resumed');
});

// Supervisor Queue Worker Management
// -----------------------------------
// To use queue workers, set 'queue_worker_name' in your deploy.php:
//   set('queue_worker_name', 'myapp-prod-worker');
// Or use a callback for dynamic naming:
//   set('queue_worker_name', fn() => 'myapp-' . getStage() . '-worker');

set('queue_worker_processes', 2);

desc('Install supervisor on server (run as root)');
task('supervisor:install', function () {
    run('apt-get update && apt-get install -y supervisor');
    run('systemctl enable supervisor');
    run('systemctl start supervisor');
    info('Supervisor installed and started');
});

desc('Setup queue worker supervisor config');
task('queue:setup', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        warning('queue_worker_name not set. Skipping queue:setup.');

        return;
    }

    if (! test('[ -x /usr/bin/supervisorctl ]')) {
        warning('Supervisor is not installed. Run: dep supervisor:install server');

        return;
    }

    $numProcs = get('queue_worker_processes', 2);
    $deployPath = run('echo $HOME').'/'.basename(get('deploy_path'));

    $config = <<<CONF
[program:{$workerName}]
process_name=%(program_name)s_%(process_num)02d
command=php {$deployPath}/current/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deployer
numprocs={$numProcs}
redirect_stderr=true
stdout_logfile={$deployPath}/shared/storage/logs/queue-worker.log
stopwaitsecs=3600
CONF;

    $configPath = "/etc/supervisor/conf.d/{$workerName}.conf";

    if (test("[ -f {$configPath} ]")) {
        info("Queue worker config already exists at {$configPath}");

        return;
    }

    sudo('tee '.$configPath.' > /dev/null <<EOF
'.$config.'
EOF');
    sudo('supervisorctl reread');
    sudo('supervisorctl update');
    info("Queue worker {$workerName} configured successfully");
});

desc('Reload supervisor configuration (reread + update)');
task('queue:reload', function () {
    sudo('supervisorctl reread');
    sudo('supervisorctl update');
    info('Supervisor configuration reloaded');
});

desc('Restart queue workers via supervisor');
task('queue:restart', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        return;
    }

    // First check if supervisor is installed
    if (! test('[ -x /usr/bin/supervisorctl ]')) {
        warning('Supervisor not installed, skipping queue restart');

        return;
    }

    // Check if the worker is configured
    $workerExists = run("supervisorctl status {$workerName}:* 2>&1 || echo 'NOT_FOUND'");

    if (str_contains($workerExists, 'NOT_FOUND') || str_contains($workerExists, 'no such')) {
        info("Queue worker {$workerName} not configured yet, reloading supervisor config...");
        sudo('supervisorctl reread');
        sudo('supervisorctl update');

        return;
    }

    // Restart the workers
    try {
        sudo("supervisorctl restart {$workerName}:*");

        // Verify workers are running
        $status = run("supervisorctl status {$workerName}:* 2>&1");

        if (str_contains($status, 'RUNNING')) {
            info('Queue workers restarted successfully');
        } else {
            warning("Queue workers may not be running correctly: {$status}");
        }
    } catch (\Throwable $e) {
        throw new \RuntimeException("Failed to restart queue workers: " . $e->getMessage());
    }
});

desc('Show queue worker status');
task('queue:status', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        warning('queue_worker_name not set.');

        return;
    }

    try {
        $status = sudo("supervisorctl status {$workerName}:*");
        writeln($status);
    } catch (\Throwable) {
        warning('Queue workers not configured. Run: dep queue:setup <environment>');
    }
});

desc('Stop queue workers');
task('queue:stop', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        return;
    }

    if (! test('[ -x /usr/bin/supervisorctl ]')) {
        warning('Supervisor not installed');

        return;
    }

    $workerExists = run("supervisorctl status {$workerName}:* 2>&1 || echo 'NOT_FOUND'");

    if (str_contains($workerExists, 'NOT_FOUND') || str_contains($workerExists, 'no such')) {
        info("Queue worker {$workerName} not configured");

        return;
    }

    sudo("supervisorctl stop {$workerName}:*");
    info('Queue workers stopped');
});

desc('Start queue workers');
task('queue:start', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        return;
    }

    if (! test('[ -x /usr/bin/supervisorctl ]')) {
        warning('Supervisor not installed');

        return;
    }

    $workerExists = run("supervisorctl status {$workerName}:* 2>&1 || echo 'NOT_FOUND'");

    if (str_contains($workerExists, 'NOT_FOUND') || str_contains($workerExists, 'no such')) {
        warning("Queue worker {$workerName} not configured. Run: dep queue:setup <environment>");

        return;
    }

    sudo("supervisorctl start {$workerName}:*");

    // Verify started
    $status = run("supervisorctl status {$workerName}:* 2>&1");

    if (str_contains($status, 'RUNNING')) {
        info('Queue workers started');
    } else {
        warning("Queue workers may not have started correctly: {$status}");
    }
});
