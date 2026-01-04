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
