<?php

namespace Deployer;

desc('Restart PHP-FPM');
task('php-fpm:restart', function () {
    $version = get('php_version', '8.4');
    sudo("systemctl restart php{$version}-fpm");
    info("Restarted PHP {$version}-FPM");
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
    info("Maintenance mode enabled (secret: {$secret})");
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
        return;
    }

    run('cd {{deploy_path}}/current && {{bin/php}} artisan horizon:terminate 2>/dev/null || true');
    info('Horizon workers terminating...');
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
    $deployPath = run('echo $HOME') . '/' . basename(get('deploy_path'));

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

    run('echo ' . escapeshellarg($config) . " | sudo tee {$configPath}");
    run('sudo supervisorctl reread');
    run('sudo supervisorctl update');
    info("Queue worker {$workerName} configured successfully");
});

desc('Restart queue workers via supervisor');
task('queue:restart', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        return;
    }

    run("sudo supervisorctl restart {$workerName}:* 2>/dev/null || echo 'Queue worker not configured'");
});

desc('Show queue worker status');
task('queue:status', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        warning('queue_worker_name not set.');

        return;
    }

    $status = run("sudo supervisorctl status {$workerName}:* 2>/dev/null || echo 'Queue workers not configured'");
    writeln($status);
});

desc('Stop queue workers');
task('queue:stop', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        return;
    }

    run("sudo supervisorctl stop {$workerName}:* 2>/dev/null || true");
    info('Queue workers stopped');
});

desc('Start queue workers');
task('queue:start', function () {
    $workerName = get('queue_worker_name', '');

    if (empty($workerName)) {
        return;
    }

    run("sudo supervisorctl start {$workerName}:* 2>/dev/null || true");
    info('Queue workers started');
});
