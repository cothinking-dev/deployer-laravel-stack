<?php

namespace Deployer;

set('env_base', function () {
    $secrets = get('secrets');

    return [
        'APP_NAME' => get('application', 'Laravel'),
        'APP_ENV' => get('app_env', 'production'),
        'APP_KEY' => $secrets['app_key'] ?? '',
        'APP_DEBUG' => get('app_debug', 'false'),
        'APP_TIMEZONE' => get('app_timezone', 'UTC'),
        'APP_URL' => get('url'),
        'APP_LOCALE' => 'en',
        'APP_FALLBACK_LOCALE' => 'en',
        'APP_FAKER_LOCALE' => 'en_US',
        'APP_MAINTENANCE_DRIVER' => 'file',

        'BCRYPT_ROUNDS' => '12',

        'LOG_CHANNEL' => 'stack',
        'LOG_STACK' => 'single',
        'LOG_DEPRECATIONS_CHANNEL' => 'null',
        'LOG_LEVEL' => get('log_level', 'error'),

        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432',
        'DB_DATABASE' => get('db_name'),
        'DB_USERNAME' => get('db_username', 'deployer'),
        'DB_PASSWORD' => $secrets['db_password'] ?? '',

        'SESSION_DRIVER' => 'redis',
        'SESSION_LIFETIME' => '120',
        'SESSION_ENCRYPT' => 'false',
        'SESSION_PATH' => '/',
        'SESSION_DOMAIN' => 'null',

        'BROADCAST_CONNECTION' => 'log',
        'QUEUE_CONNECTION' => 'redis',

        'CACHE_STORE' => 'redis',

        'FILESYSTEM_DISK' => 'local',

        'REDIS_CLIENT' => 'predis',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PASSWORD' => 'null',
        'REDIS_PORT' => '6379',
        'REDIS_DB' => get('redis_db', '0'),

        'VITE_APP_NAME' => '${APP_NAME}',
    ];
});

desc('Generate .env file from Deployer configuration');
task('deploy:env', function () {
    $baseEnv = get('env_base');
    $extras = has('env_extras') ? get('env_extras') : [];

    $env = array_merge($baseEnv, $extras);
    $content = envToString($env);

    run('mkdir -p {{deploy_path}}/shared');

    $path = '{{deploy_path}}/shared/.env';
    run('echo ' . escapeshellarg($content) . " > {$path}");
    run("chmod 640 {$path}");

    info('Generated .env for: ' . getStage());
});
