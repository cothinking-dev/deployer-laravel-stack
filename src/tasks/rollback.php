<?php

namespace Deployer;

set('rollback_keep_current_on_fail', false);

desc('Rollback to previous release');
task('rollback', function () {
    $releases = get('releases_list');

    if (count($releases) < 2) {
        warning('No previous release available to rollback to');

        return;
    }

    $currentRelease = $releases[0];
    $previousRelease = $releases[1];

    info("Rolling back from {$currentRelease} to {$previousRelease}...");

    // Put app in maintenance mode if it exists
    if (test('[ -f {{deploy_path}}/current/artisan ]')) {
        run('cd {{deploy_path}}/current && {{bin/php}} artisan down --retry=60 2>/dev/null || true');
    }

    // Update symlink to previous release
    run('cd {{deploy_path}} && {{bin/symlink}} releases/' . $previousRelease . ' current');

    // Restart PHP-FPM
    $phpVersion = get('php_version', '8.4');

    try {
        sudo("systemctl restart php{$phpVersion}-fpm");
        info("Restarted PHP {$phpVersion}-FPM");
    } catch (\Throwable $e) {
        warning("Failed to restart PHP-FPM: " . $e->getMessage());
    }

    // Restart queue workers if configured
    $workerName = get('queue_worker_name', '');

    if ($workerName && test('[ -x /usr/bin/supervisorctl ]')) {
        try {
            sudo("supervisorctl restart {$workerName}:* 2>/dev/null || true");
            info('Queue workers restarted');
        } catch (\Throwable $e) {
            warning("Failed to restart queue workers: " . $e->getMessage());
        }
    }

    // Bring app back up
    run('cd {{deploy_path}}/current && {{bin/php}} artisan up');

    info("Rolled back to release: {$previousRelease}");

    // Verify the rollback
    $verifyEnabled = get('verify_after_rollback', true);

    if ($verifyEnabled) {
        info('Verifying rollback...');

        try {
            invoke('deploy:verify:quick');
            info('Rollback verification passed');
        } catch (\Throwable $e) {
            warning('Rollback verification failed: ' . $e->getMessage());
            warning('Manual intervention may be required');
        }
    }
});

desc('Automatic rollback triggered by deploy failure');
task('deploy:rollback-on-failure', function () {
    $releases = get('releases_list');

    // Only rollback if we have a previous release and the current deploy actually created a new one
    if (count($releases) < 2) {
        info('No previous release to rollback to');

        return;
    }

    // Check if health check failed (set by verify task)
    $healthCheckFailed = has('health_check_failed') && get('health_check_failed');

    if ($healthCheckFailed) {
        warning('Health check failed, initiating automatic rollback...');
        invoke('rollback');

        return;
    }

    // For other failures, check if we should auto-rollback
    $autoRollback = get('auto_rollback_on_failure', false);

    if (! $autoRollback) {
        info('Auto-rollback disabled. Run `dep rollback` manually if needed.');

        return;
    }

    warning('Deployment failed, initiating automatic rollback...');
    invoke('rollback');
});

desc('Show available releases for rollback');
task('rollback:list', function () {
    $releases = get('releases_list');

    if (empty($releases)) {
        warning('No releases found');

        return;
    }

    writeln('Available releases:');
    writeln('');

    foreach ($releases as $index => $release) {
        $marker = $index === 0 ? ' [current]' : '';
        $rollbackable = $index > 0 ? ' <- rollback target' : '';
        writeln("  {$release}{$marker}{$rollbackable}");
    }

    writeln('');
    info('Run `dep rollback` to restore the previous release');
});

desc('Rollback to a specific release');
task('rollback:to', function () {
    $releases = get('releases_list');

    if (count($releases) < 2) {
        warning('No releases available to rollback to');

        return;
    }

    writeln('Available releases:');

    foreach ($releases as $index => $release) {
        $marker = $index === 0 ? ' [current]' : '';
        writeln("  [{$index}] {$release}{$marker}");
    }

    $choice = ask('Enter release number to restore:', '1');
    $targetIndex = (int) $choice;

    if ($targetIndex === 0) {
        warning('Cannot rollback to current release');

        return;
    }

    if (! isset($releases[$targetIndex])) {
        warning('Invalid release selection');

        return;
    }

    $targetRelease = $releases[$targetIndex];

    if (! askConfirmation("Rollback to release {$targetRelease}?", true)) {
        info('Rollback cancelled');

        return;
    }

    // Put app in maintenance mode
    if (test('[ -f {{deploy_path}}/current/artisan ]')) {
        run('cd {{deploy_path}}/current && {{bin/php}} artisan down --retry=60 2>/dev/null || true');
    }

    // Update symlink
    run('cd {{deploy_path}} && {{bin/symlink}} releases/' . $targetRelease . ' current');

    // Restart services
    $phpVersion = get('php_version', '8.4');
    sudo("systemctl restart php{$phpVersion}-fpm");

    $workerName = get('queue_worker_name', '');

    if ($workerName && test('[ -x /usr/bin/supervisorctl ]')) {
        sudo("supervisorctl restart {$workerName}:* 2>/dev/null || true");
    }

    // Bring app back up
    run('cd {{deploy_path}}/current && {{bin/php}} artisan up');

    info("Restored release: {$targetRelease}");
});

desc('Clean up failed release (remove current broken release)');
task('rollback:cleanup', function () {
    $releases = get('releases_list');

    if (count($releases) < 2) {
        warning('Only one release exists, cannot cleanup');

        return;
    }

    $currentRelease = $releases[0];

    if (! askConfirmation("Remove failed release {$currentRelease} and keep previous?", false)) {
        info('Cleanup cancelled');

        return;
    }

    // First rollback
    invoke('rollback');

    // Then remove the failed release
    run("rm -rf {{deploy_path}}/releases/{$currentRelease}");

    info("Removed failed release: {$currentRelease}");
});
