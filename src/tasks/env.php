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

set('env_safe_mode', false);

// Required env vars that must have values (not empty, not placeholders)
set('env_required', ['APP_KEY', 'DB_PASSWORD']);

/**
 * Build the final .env array by merging base, shared, and per-environment overrides.
 *
 * @param  bool  $strict  If true, validate all secrets are resolved
 */
function buildEnv(bool $strict = false): array
{
    $base = get('env_base');
    $shared = has('shared_env') ? get('shared_env') : [];
    $overrides = has('env_overrides') ? get('env_overrides') : [];

    $merged = array_merge($base, $shared, $overrides);
    $resolved = resolveSecrets($merged, $strict);

    // Validate required env vars have real values
    if ($strict) {
        $required = get('env_required', ['APP_KEY', 'DB_PASSWORD']);
        $missing = [];

        foreach ($required as $key) {
            if (! isset($resolved[$key]) || $resolved[$key] === '' || preg_match('/^\{[\w]+\}$/', $resolved[$key])) {
                $missing[] = $key;
            }
        }

        if (! empty($missing)) {
            throw new \RuntimeException(
                'Required environment variables missing or unresolved: ' . implode(', ', $missing)
            );
        }
    }

    return $resolved;
}

/**
 * Validate that the .env file doesn't contain unresolved placeholders.
 */
function validateEnvFile(string $path): array
{
    $content = run("cat {$path} 2>/dev/null || echo ''");
    $issues = [];

    // Check for unresolved placeholders
    if (preg_match_all('/^(\w+)=\{(\w+)\}/m', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $issues[] = "Unresolved placeholder: {$match[1]}={$match[0]}";
        }
    }

    // Check for empty required values
    $required = get('env_required', ['APP_KEY', 'DB_PASSWORD']);

    foreach ($required as $key) {
        if (preg_match("/^{$key}=$/m", $content)) {
            $issues[] = "Empty required value: {$key}";
        }
    }

    return $issues;
}

desc('Generate .env file from Deployer configuration');
task('deploy:env', function () {
    $path = '{{deploy_path}}/shared/.env';
    $safeMode = get('env_safe_mode', true);

    run('mkdir -p {{deploy_path}}/shared');

    if ($safeMode && test("[ -f {$path} ]")) {
        // Validate existing .env file
        $issues = validateEnvFile($path);

        if (! empty($issues)) {
            warning('.env exists but has issues:');

            foreach ($issues as $issue) {
                warning("  - {$issue}");
            }

            warning('Run deploy:env:force to regenerate, or fix manually.');
        } else {
            info('.env exists and is valid (safe mode). Skipping. Set env_safe_mode=false to overwrite.');
        }

        return;
    }

    // Build env with strict validation for new files
    $env = buildEnv(strict: true);
    $content = envToString($env);

    run('echo '.escapeshellarg($content)." > {$path}");
    run("chmod 640 {$path}");

    info('Generated .env for: '.getStage());
});

desc('Force regenerate .env file (overwrites existing)');
task('deploy:env:force', function () {
    // Use strict validation when force regenerating
    $env = buildEnv(strict: true);
    $content = envToString($env);

    run('mkdir -p {{deploy_path}}/shared');

    $path = '{{deploy_path}}/shared/.env';

    if (test("[ -f {$path} ]")) {
        $timestamp = date('Y-m-d-His');
        run("cp {$path} {$path}.backup.{$timestamp}");
        info("Backed up existing .env to .env.backup.{$timestamp}");
    }

    run('echo '.escapeshellarg($content)." > {$path}");
    run("chmod 640 {$path}");

    info('Generated .env for: '.getStage());
});

desc('Validate current .env file');
task('env:validate', function () {
    $path = '{{deploy_path}}/shared/.env';

    if (! test("[ -f {$path} ]")) {
        warning('No .env file found');

        return;
    }

    $issues = validateEnvFile($path);

    if (empty($issues)) {
        info('.env file is valid');
    } else {
        warning('.env file has issues:');

        foreach ($issues as $issue) {
            warning("  - {$issue}");
        }
    }
});

desc('Show current .env values (masked secrets)');
task('env:show', function () {
    $path = '{{deploy_path}}/shared/.env';

    if (! test("[ -f {$path} ]")) {
        warning('No .env file found');

        return;
    }

    $content = run("cat {$path}");

    $masked = preg_replace_callback(
        '/^(.*(?:KEY|SECRET|PASSWORD|TOKEN|CREDENTIALS).*)=(.+)$/mi',
        fn ($m) => $m[1].'='.str_repeat('*', min(strlen($m[2]), 20)),
        $content
    );

    writeln($masked);
});

desc('Show .env backups');
task('env:backups', function () {
    $path = '{{deploy_path}}/shared';
    $backups = run("ls -la {$path}/.env.backup.* 2>/dev/null || echo 'No backups found'");
    writeln($backups);
});

desc('Restore .env from backup');
task('env:restore', function () {
    $path = '{{deploy_path}}/shared';
    $backups = run("ls -1 {$path}/.env.backup.* 2>/dev/null | sort -r");

    if (empty(trim($backups))) {
        warning('No backups found');

        return;
    }

    $backupFiles = array_filter(explode("\n", trim($backups)));
    writeln('Available backups:');
    foreach ($backupFiles as $i => $file) {
        writeln("  [{$i}] ".basename($file));
    }

    $choice = ask('Enter backup number to restore:', '0');
    $selectedBackup = $backupFiles[(int) $choice] ?? null;

    if (! $selectedBackup) {
        warning('Invalid selection');

        return;
    }

    $timestamp = date('Y-m-d-His');
    run("cp {$path}/.env {$path}/.env.pre-restore.{$timestamp}");
    run("cp {$selectedBackup} {$path}/.env");
    info('Restored from: '.basename($selectedBackup));
});
