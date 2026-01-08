<?php

namespace Deployer;

desc('Clear all application caches');
task('artisan:cache:clear-all', function () {
    within('{{release_or_current_path}}', function () {
        run('{{bin/php}} artisan cache:clear', timeout: 60);
        run('{{bin/php}} artisan config:clear', timeout: 30);
        run('{{bin/php}} artisan route:clear', timeout: 30);
        run('{{bin/php}} artisan view:clear', timeout: 60);
        run('{{bin/php}} artisan event:clear', timeout: 30);
    });
    info('All caches cleared');
});

desc('Rebuild application caches for production');
task('artisan:cache:rebuild', function () {
    within('{{release_or_current_path}}', function () {
        // Config and route cache are critical - retry on failure
        runWithRetry('{{bin/php}} artisan config:cache', maxAttempts: 3, delaySeconds: 2);
        runWithRetry('{{bin/php}} artisan route:cache', maxAttempts: 3, delaySeconds: 2);

        // View cache - retry but allow graceful failure (views compile on-demand)
        try {
            runWithRetry('{{bin/php}} artisan view:cache', maxAttempts: 3, delaySeconds: 2);
        } catch (\Throwable $e) {
            warning('View cache failed (non-fatal): '.$e->getMessage());
            info('Views will be compiled on-demand');
        }

        // Event cache - retry but allow graceful failure
        try {
            runWithRetry('{{bin/php}} artisan event:cache', maxAttempts: 3, delaySeconds: 2);
        } catch (\Throwable $e) {
            warning('Event cache failed (non-fatal): '.$e->getMessage());
        }
    });
    info('Application caches rebuilt');
});

desc('Clear and rebuild all caches');
task('artisan:cache:refresh', [
    'artisan:cache:clear-all',
    'artisan:cache:rebuild',
]);

desc('Check if vendor directory needs refresh based on composer.lock');
task('composer:check-cache', function () {
    $lockHash = run('md5sum {{release_path}}/composer.lock 2>/dev/null | cut -d" " -f1 || echo ""');
    $vendorHash = run('cat {{release_path}}/vendor/.composer-lock-hash 2>/dev/null || echo ""');

    if (trim($lockHash) === trim($vendorHash)) {
        info('composer.lock unchanged, vendor directory is current');
        set('composer_cache_valid', true);
    } else {
        info('composer.lock changed, will reinstall dependencies');
        set('composer_cache_valid', false);
    }
});

desc('Save composer.lock hash after install');
task('composer:save-hash', function () {
    run('md5sum {{release_path}}/composer.lock | cut -d" " -f1 > {{release_path}}/vendor/.composer-lock-hash');
});
