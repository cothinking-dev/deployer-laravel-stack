<?php

namespace Deployer;

desc('Clear all application caches');
task('artisan:cache:clear-all', function () {
    within('{{release_or_current_path}}', function () {
        run('{{bin/php}} artisan cache:clear');
        run('{{bin/php}} artisan config:clear');
        run('{{bin/php}} artisan route:clear');
        run('{{bin/php}} artisan view:clear');
        run('{{bin/php}} artisan event:clear');
    });
    info('All caches cleared');
});

desc('Rebuild application caches for production');
task('artisan:cache:rebuild', function () {
    within('{{release_or_current_path}}', function () {
        run('{{bin/php}} artisan config:cache');
        run('{{bin/php}} artisan route:cache');
        run('{{bin/php}} artisan view:cache');
        run('{{bin/php}} artisan event:cache');
    });
    info('Application caches rebuilt');
});

desc('Clear and rebuild all caches');
task('artisan:cache:refresh', [
    'artisan:cache:clear-all',
    'artisan:cache:rebuild',
]);
