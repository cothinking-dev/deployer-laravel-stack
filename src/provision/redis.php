<?php

namespace Deployer;

desc('Install Redis server');
task('provision:redis', function () {
    info('Installing Redis...');

    sudo('apt-get update');
    sudo('apt-get install -y redis-server');

    sudo("sed -i 's/^bind .*/bind 127.0.0.1 ::1/' /etc/redis/redis.conf");
    sudo("sed -i 's/^protected-mode .*/protected-mode yes/' /etc/redis/redis.conf");

    sudo('systemctl enable redis-server');
    sudo('systemctl restart redis-server');

    $ping = run('redis-cli ping');
    info("Redis installed: {$ping}");
});

desc('Show Redis status');
task('redis:status', function () {
    $status = sudo('systemctl status redis-server --no-pager -l');
    writeln($status);
});

desc('Show Redis info');
task('redis:info', function () {
    $info = run('redis-cli info server | head -20');
    writeln($info);
});

desc('Flush Redis cache (use with caution)');
task('redis:flush', function () {
    if (! askConfirmation('This will delete ALL Redis data. Continue?', false)) {
        info('Aborted.');

        return;
    }

    run('redis-cli flushall');
    warning('Redis cache flushed');
});
