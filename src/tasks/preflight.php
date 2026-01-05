<?php

namespace Deployer;

// Configurable thresholds
set('preflight_disk_threshold_mb', 1024); // 1GB minimum free space
set('preflight_memory_threshold_mb', 512); // 512MB minimum available memory
set('preflight_enabled', true);

/**
 * Pre-flight check result handler.
 */
function preflightCheck(string $name, bool $passed, string $message): void
{
    if ($passed) {
        info("[PASS] {$name}: {$message}");
    } else {
        throw new \RuntimeException("[FAIL] {$name}: {$message}");
    }
}

/**
 * Parse batched preflight results from remote script output.
 *
 * @return array<string, array{status: string, message: string}>
 */
function parsePreflightResults(string $output): array
{
    $results = [];
    foreach (explode("\n", trim($output)) as $line) {
        if (preg_match('/^PREFLIGHT\|([^|]+)\|([^|]+)\|(.*)$/', $line, $matches)) {
            $results[$matches[1]] = [
                'status' => $matches[2],
                'message' => $matches[3],
            ];
        }
    }

    return $results;
}

desc('Run all pre-flight checks before deployment');
task('deploy:preflight', function () {
    if (! get('preflight_enabled', true)) {
        info('Pre-flight checks disabled, skipping...');

        return;
    }

    info('Running pre-flight checks...');
    writeln('');

    $stage = getStage();
    $phpVersion = get('php_version', '8.4');
    $diskThreshold = get('preflight_disk_threshold_mb', 1024);
    $memThreshold = get('preflight_memory_threshold_mb', 512);
    $deployPath = get('deploy_path');
    $domain = get('domain', '');

    // Build batched remote script - single SSH call for all system checks
    $remoteScript = <<<BASH
#!/bin/bash
# Batched preflight checks - outputs structured results

# PHP-FPM check
fpm_status=\$(systemctl is-active php{$phpVersion}-fpm 2>/dev/null || echo 'inactive')
if [[ "\$fpm_status" == "active" ]]; then
    echo "PREFLIGHT|PHP-FPM|PASS|php{$phpVersion}-fpm is running"
else
    echo "PREFLIGHT|PHP-FPM|FAIL|php{$phpVersion}-fpm is not running. Start with: sudo systemctl start php{$phpVersion}-fpm"
fi

# Redis service check
redis_status=\$(systemctl is-active redis-server 2>/dev/null || echo 'inactive')
if [[ "\$redis_status" == "active" ]]; then
    echo "PREFLIGHT|Redis|PASS|redis-server is running"
else
    echo "PREFLIGHT|Redis|FAIL|redis-server is not running. Start with: sudo systemctl start redis-server"
fi

# PostgreSQL check
pg_status=\$(systemctl is-active postgresql 2>/dev/null || echo 'inactive')
if [[ "\$pg_status" == "active" ]]; then
    echo "PREFLIGHT|PostgreSQL|PASS|postgresql is running"
else
    echo "PREFLIGHT|PostgreSQL|FAIL|postgresql is not running. Start with: sudo systemctl start postgresql"
fi

# Disk space check
disk_available=\$(df -BM {$deployPath} 2>/dev/null | tail -1 | awk '{print \$4}' | tr -d 'M')
if [[ "\$disk_available" -ge {$diskThreshold} ]]; then
    echo "PREFLIGHT|Disk Space|PASS|Available: \${disk_available}MB (threshold: {$diskThreshold}MB)"
else
    echo "PREFLIGHT|Disk Space|FAIL|Only \${disk_available}MB available, need at least {$diskThreshold}MB"
fi

# Memory check
mem_available=\$(free -m | awk '/^Mem:/ {print \$7}')
if [[ "\$mem_available" -ge {$memThreshold} ]]; then
    echo "PREFLIGHT|Memory|PASS|Available: \${mem_available}MB (threshold: {$memThreshold}MB)"
else
    echo "PREFLIGHT|Memory|FAIL|Only \${mem_available}MB available, need at least {$memThreshold}MB"
fi

# Deploy path check
deploy_path="{$deployPath}"
parent_path=\$(dirname "\$deploy_path")
if [[ -d "\$deploy_path" ]]; then
    if [[ -w "\$deploy_path" ]]; then
        echo "PREFLIGHT|Deploy Path|PASS|Deploy path \$deploy_path is writable"
    else
        echo "PREFLIGHT|Deploy Path|FAIL|Deploy path \$deploy_path is not writable"
    fi
else
    if [[ -w "\$parent_path" ]]; then
        echo "PREFLIGHT|Deploy Path|PASS|Deploy path will be created in \$parent_path"
    else
        echo "PREFLIGHT|Deploy Path|FAIL|Cannot create deploy path - parent \$parent_path is not writable"
    fi
fi

# Redis connectivity check
redis_ping=\$(redis-cli ping 2>/dev/null || echo 'fail')
if [[ "\$redis_ping" == "PONG" ]]; then
    echo "PREFLIGHT|Redis Connection|PASS|Redis responding to ping"
else
    echo "PREFLIGHT|Redis Connection|FAIL|Redis not responding"
fi

# Caddy check (if domain configured)
if [[ -n "{$domain}" ]]; then
    caddy_status=\$(systemctl is-active caddy 2>/dev/null || echo 'inactive')
    if [[ "\$caddy_status" == "active" ]]; then
        echo "PREFLIGHT|Caddy|PASS|Caddy web server is running"
    else
        echo "PREFLIGHT|Caddy|FAIL|Caddy is not running. Start with: sudo systemctl start caddy"
    fi
fi
BASH;

    // Execute batched checks in single SSH call
    $output = run($remoteScript);
    $results = parsePreflightResults($output);

    // Process results
    $requiredChecks = ['PHP-FPM', 'Redis', 'PostgreSQL', 'Disk Space', 'Memory', 'Deploy Path', 'Redis Connection'];
    if ($domain) {
        $requiredChecks[] = 'Caddy';
    }

    foreach ($requiredChecks as $check) {
        if (! isset($results[$check])) {
            throw new \RuntimeException("[FAIL] {$check}: Check did not return a result");
        }

        $result = $results[$check];
        preflightCheck($check, $result['status'] === 'PASS', $result['message']);
    }

    // Local checks that can't be batched remotely

    // Validate secrets don't contain unresolved placeholders
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

    // Database connectivity check (requires secret handling)
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

    // Batch all service checks into single SSH call
    $script = <<<BASH
echo "Service Status:"
echo ""
for svc in "php{$phpVersion}-fpm:PHP-FPM {$phpVersion}" "redis-server:Redis" "postgresql:PostgreSQL" "caddy:Caddy"; do
    name="\${svc%%:*}"
    label="\${svc#*:}"
    status=\$(systemctl is-active "\$name" 2>/dev/null || echo 'not installed')
    if [[ "\$status" == "active" ]]; then
        echo "  [OK] \$label: \$status"
    else
        echo "  [!!] \$label: \$status"
    fi
done
BASH;

    $output = run($script);
    writeln($output);
});
