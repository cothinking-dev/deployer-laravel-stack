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

after('provision:php', 'php:opcache');

desc('Show installed PHP version and extensions');
task('php:info', function () {
    $version = run('php -v | head -1');
    writeln($version);
    writeln('');
    writeln('Loaded extensions:');
    $extensions = run('php -m');
    writeln($extensions);
});

desc('Configure OPcache for production');
task('php:opcache', function () {
    $version = get('php_version', '8.4');
    $configPath = "/etc/php/{$version}/mods-available/opcache.ini";

    // Production-optimized OPcache settings for Laravel
    $config = <<<'INI'
; OPcache settings optimized for Laravel production
zend_extension=opcache.so
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=64
opcache.max_accelerated_files=30000
opcache.validate_timestamps=0
opcache.revalidate_freq=0
opcache.save_comments=1
opcache.fast_shutdown=1
INI;

    sudo("tee {$configPath} > /dev/null << 'EOF'\n{$config}\nEOF");
    sudo("systemctl restart php{$version}-fpm");

    info("OPcache configured for production (validate_timestamps=0)");
    info("Note: PHP-FPM restart is required after each deploy to load new code");
});

desc('Show OPcache status');
task('php:opcache:status', function () {
    $status = run("php -r \"var_export(opcache_get_status(false));\" 2>/dev/null || echo 'OPcache not available'");
    writeln($status);
});
