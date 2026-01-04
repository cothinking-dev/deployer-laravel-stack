<?php

namespace Deployer;

desc('Install PHP with common extensions');
task('provision:php', function () {
    $version = get('php_version', '8.4');

    info("Installing PHP {$version}...");

    sudo('apt-get update');
    sudo('apt-get install -y software-properties-common');
    sudo('add-apt-repository -y ppa:ondrej/php');
    sudo('apt-get update');

    $extensions = [
        'fpm', 'cli', 'pgsql', 'mbstring', 'xml', 'curl',
        'zip', 'gd', 'redis', 'bcmath', 'intl', 'opcache', 'readline',
    ];

    $packages = array_map(fn ($ext) => "php{$version}-{$ext}", $extensions);
    sudo('apt-get install -y ' . implode(' ', $packages));

    sudo("systemctl enable php{$version}-fpm");
    sudo("systemctl start php{$version}-fpm");

    $phpVersion = run('php -v | head -1');
    info("PHP installed: {$phpVersion}");
});

desc('Show installed PHP version and extensions');
task('php:info', function () {
    $version = run('php -v | head -1');
    writeln($version);
    writeln('');
    writeln('Loaded extensions:');
    $extensions = run('php -m');
    writeln($extensions);
});
