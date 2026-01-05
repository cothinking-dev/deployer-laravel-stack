<?php

namespace Deployer;

// Configurable thresholds
set('preflight_disk_threshold_mb', 1024); // 1GB minimum free space
set('preflight_memory_threshold_mb', 512); // 512MB minimum available memory
set('preflight_enabled', true);

/**
 * Pre-flight check results tracking.
 *
 * @var array<string, array{passed: bool, message: string}>
 */
function preflightCheck(string $name, bool $passed, string $message): void
{
    if ($passed) {
        info("[PASS] {$name}: {$message}");
    } else {
        throw new \RuntimeException("[FAIL] {$name}: {$message}");
    }
}

desc('Run all pre-flight checks before deployment');
task('deploy:preflight', function () {
    if (! get('preflight_enabled', true)) {
        info('Pre-flight checks disabled, skipping...');

        return;
    }

    info('Running pre-flight checks...');
    writeln('');

    // Track if any checks fail
    $stage = getStage();

    // 1. Check PHP-FPM is running
    $phpVersion = get('php_version', '8.4');
    $fpmStatus = run("systemctl is-active php{$phpVersion}-fpm 2>/dev/null || echo 'inactive'");

    preflightCheck(
        'PHP-FPM',
        trim($fpmStatus) === 'active',
        trim($fpmStatus) === 'active'
            ? "php{$phpVersion}-fpm is running"
            : "php{$phpVersion}-fpm is not running. Start with: sudo systemctl start php{$phpVersion}-fpm"
    );

    // 2. Check Redis is running
    $redisStatus = run("systemctl is-active redis-server 2>/dev/null || echo 'inactive'");

    preflightCheck(
        'Redis',
        trim($redisStatus) === 'active',
        trim($redisStatus) === 'active'
            ? 'redis-server is running'
            : 'redis-server is not running. Start with: sudo systemctl start redis-server'
    );

    // 3. Check PostgreSQL is running
    $pgStatus = run("systemctl is-active postgresql 2>/dev/null || echo 'inactive'");

    preflightCheck(
        'PostgreSQL',
        trim($pgStatus) === 'active',
        trim($pgStatus) === 'active'
            ? 'postgresql is running'
            : 'postgresql is not running. Start with: sudo systemctl start postgresql'
    );

    // 4. Check disk space
    $thresholdMb = get('preflight_disk_threshold_mb', 1024);
    $deployPath = get('deploy_path');

    // Get available disk space in MB
    $diskOutput = run("df -BM {$deployPath} 2>/dev/null | tail -1 | awk '{print \$4}' | tr -d 'M'");
    $availableMb = (int) trim($diskOutput);

    preflightCheck(
        'Disk Space',
        $availableMb >= $thresholdMb,
        $availableMb >= $thresholdMb
            ? "Available: {$availableMb}MB (threshold: {$thresholdMb}MB)"
            : "Only {$availableMb}MB available, need at least {$thresholdMb}MB. Free up disk space before deploying."
    );

    // 5. Check available memory
    $memThresholdMb = get('preflight_memory_threshold_mb', 512);
    $memOutput = run("free -m | awk '/^Mem:/ {print \$7}'");
    $availableMemMb = (int) trim($memOutput);

    preflightCheck(
        'Memory',
        $availableMemMb >= $memThresholdMb,
        $availableMemMb >= $memThresholdMb
            ? "Available: {$availableMemMb}MB (threshold: {$memThresholdMb}MB)"
            : "Only {$availableMemMb}MB available, need at least {$memThresholdMb}MB. Close applications or add swap."
    );

    // 6. Check deploy path is writable
    $parentPath = dirname(str_replace('~', run('echo $HOME'), $deployPath));

    if (test("[ -d {$deployPath} ]")) {
        $writable = test("[ -w {$deployPath} ]");
        preflightCheck(
            'Deploy Path',
            $writable,
            $writable
                ? "Deploy path {$deployPath} is writable"
                : "Deploy path {$deployPath} is not writable. Fix with: chmod 755 {$deployPath}"
        );
    } else {
        // Path doesn't exist, check parent is writable
        $parentWritable = test("[ -w {$parentPath} ]");
        preflightCheck(
            'Deploy Path',
            $parentWritable,
            $parentWritable
                ? "Deploy path will be created in {$parentPath}"
                : "Cannot create deploy path - parent {$parentPath} is not writable"
        );
    }

    // 7. Validate secrets don't contain unresolved placeholders
    $secrets = has('secrets') ? get('secrets') : [];
    $unresolvedSecrets = [];

    foreach ($secrets as $key => $value) {
        if (is_string($value) && preg_match('/^\{[\w]+\}$/', $value)) {
            $unresolvedSecrets[] = $key;
        }
    }

    preflightCheck(
        'Secrets',
        empty($unresolvedSecrets),
        empty($unresolvedSecrets)
            ? 'All secrets are resolved'
            : 'Unresolved secret placeholders: ' . implode(', ', $unresolvedSecrets) . '. Check environment variables.'
    );

    // 8. Check database connectivity
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');
    $dbPass = $secrets['db_password'] ?? '';

    if ($dbName && $dbPass) {
        $dbCheck = run("PGPASSWORD='%secret%' psql -h 127.0.0.1 -U {$dbUser} -d {$dbName} -c 'SELECT 1' 2>&1 | grep -q '1 row' && echo 'ok' || echo 'fail'", secret: $dbPass);

        preflightCheck(
            'Database',
            trim($dbCheck) === 'ok',
            trim($dbCheck) === 'ok'
                ? "Connected to database {$dbName}"
                : "Cannot connect to database {$dbName}. Check credentials and that database exists."
        );
    }

    // 9. Check Redis connectivity
    $redisCheck = run("redis-cli ping 2>/dev/null || echo 'fail'");

    preflightCheck(
        'Redis Connection',
        trim($redisCheck) === 'PONG',
        trim($redisCheck) === 'PONG'
            ? 'Redis responding to ping'
            : 'Redis not responding. Check if redis-server is running and accessible.'
    );

    // 10. Check Caddy is running (if configured)
    $domain = get('domain', '');
    if ($domain) {
        $caddyStatus = run("systemctl is-active caddy 2>/dev/null || echo 'inactive'");

        preflightCheck(
            'Caddy',
            trim($caddyStatus) === 'active',
            trim($caddyStatus) === 'active'
                ? 'Caddy web server is running'
                : 'Caddy is not running. Start with: sudo systemctl start caddy'
        );
    }

    writeln('');
    info("All pre-flight checks passed for {$stage}");
});

desc('Check disk space on server');
task('preflight:disk', function () {
    $deployPath = get('deploy_path');
    $output = run("df -h {$deployPath}");
    writeln($output);
});

desc('Check memory on server');
task('preflight:memory', function () {
    $output = run('free -h');
    writeln($output);
});

desc('Check all services status');
task('preflight:services', function () {
    $phpVersion = get('php_version', '8.4');

    writeln('Service Status:');
    writeln('');

    $services = [
        "php{$phpVersion}-fpm" => "PHP-FPM {$phpVersion}",
        'redis-server' => 'Redis',
        'postgresql' => 'PostgreSQL',
        'caddy' => 'Caddy',
    ];

    foreach ($services as $service => $name) {
        $status = run("systemctl is-active {$service} 2>/dev/null || echo 'not installed'");
        $statusText = trim($status);
        $icon = $statusText === 'active' ? '[OK]' : '[!!]';
        writeln("  {$icon} {$name}: {$statusText}");
    }
});
