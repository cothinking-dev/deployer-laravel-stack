<?php

namespace Deployer;

set('verify_url', function () {
    return get('url');
});

// Health endpoint path (relative to app URL)
set('verify_health_path', '/');
set('verify_valid_codes', ['200', '301', '302', '303', '307', '308']);
set('verify_timeout', 15);
set('verify_wait', 2);
set('verify_retries', 10);
set('verify_retry_delay', 2);
set('verify_insecure', true); // Allow self-signed certs (for internal TLS)
set('verify_check_body', true); // Check response body for errors
set('verify_error_patterns', [
    'Fatal error:',
    'Parse error:',
    'syntax error,',
    'Uncaught Exception',
    'Stack trace:',
    'vendor/laravel/framework',
    '500 Internal Server Error',
    '503 Service Unavailable',
    'Whoops, looks like something went wrong',
    'The stream or file',
    'SQLSTATE[',
]);

// Deep health checks (database, redis)
set('verify_deep_health', false);
set('verify_auto_rollback', true);

desc('Verify deployment health via HTTP check');
task('deploy:verify', function () {
    $url = get('verify_url');
    $stage = getStage();
    $validCodes = get('verify_valid_codes');
    $timeout = get('verify_timeout');
    $wait = get('verify_wait');
    $retries = get('verify_retries', 3);
    $retryDelay = get('verify_retry_delay', 2);
    $insecure = get('verify_insecure', true);
    $checkBody = get('verify_check_body', true);
    $errorPatterns = get('verify_error_patterns', []);
    $autoRollback = get('verify_auto_rollback', true);

    if (empty($url)) {
        warning('No URL configured for verification (set verify_url or url)');

        return;
    }

    info("Verifying deployment for {$stage}...");

    if ($wait > 0) {
        info("Waiting {$wait}s for services to stabilize...");
        run("sleep {$wait}");
    }

    $healthPath = get('verify_health_path', '/');
    $checkUrl = rtrim($url, '/') . '/' . ltrim($healthPath, '/');
    $insecureFlag = $insecure ? '--insecure' : '';

    $passed = false;
    $lastStatusCode = '000';
    $lastBody = '';

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        info("Health check attempt {$attempt}/{$retries}...");

        // Get both status code and body
        $result = runLocally(
            "curl -s -w '\\n%{http_code}' -L --max-time {$timeout} {$insecureFlag} {$checkUrl} 2>/dev/null || echo -e '\\n000'"
        );

        $lines = explode("\n", trim($result));
        $lastStatusCode = array_pop($lines);
        $lastBody = implode("\n", $lines);

        // Check status code
        if (! in_array($lastStatusCode, $validCodes)) {
            warning("Attempt {$attempt}: HTTP {$lastStatusCode}");

            if ($attempt < $retries) {
                run("sleep {$retryDelay}");
            }

            continue;
        }

        // Check response body for error patterns
        if ($checkBody && ! empty($errorPatterns)) {
            $foundError = false;

            foreach ($errorPatterns as $pattern) {
                if (stripos($lastBody, $pattern) !== false) {
                    warning("Attempt {$attempt}: Response contains error pattern: {$pattern}");
                    $foundError = true;

                    break;
                }
            }

            if ($foundError) {
                if ($attempt < $retries) {
                    run("sleep {$retryDelay}");
                }

                continue;
            }
        }

        $passed = true;

        break;
    }

    if (! $passed) {
        warning("Health check failed after {$retries} attempts (last: HTTP {$lastStatusCode})");

        // Show recent logs
        writeln('');
        writeln('Recent Laravel logs:');
        run('tail -30 {{deploy_path}}/shared/storage/logs/laravel.log 2>/dev/null || echo "No logs available"');

        // Check if auto-rollback is enabled
        if ($autoRollback) {
            warning('Auto-rollback is enabled. Triggering rollback...');
            set('health_check_failed', true);
        }

        throw new \RuntimeException("Deployment verification failed: HTTP {$lastStatusCode}");
    }

    info("Health check passed (HTTP {$lastStatusCode})");

    // Run deep health checks if enabled
    if (get('verify_deep_health', false)) {
        runDeepHealthChecks();
    }
});

/**
 * Run deep health checks for database and Redis connectivity.
 */
function runDeepHealthChecks(): void
{
    info('Running deep health checks...');

    // Check database connectivity
    $dbCheck = run('cd {{release_path}} && {{bin/php}} artisan tinker --execute="DB::select(\"SELECT 1\")" 2>&1 || echo "DB_FAILED"');

    if (str_contains($dbCheck, 'DB_FAILED') || str_contains($dbCheck, 'Exception')) {
        throw new \RuntimeException('Deep health check failed: Database connection error');
    }

    info('[OK] Database connectivity');

    // Check Redis connectivity
    $redisCheck = run('cd {{release_path}} && {{bin/php}} artisan tinker --execute="Redis::ping()" 2>&1 || echo "REDIS_FAILED"');

    if (str_contains($redisCheck, 'REDIS_FAILED') || str_contains($redisCheck, 'Exception')) {
        throw new \RuntimeException('Deep health check failed: Redis connection error');
    }

    info('[OK] Redis connectivity');

    // Check cache is working
    $cacheCheck = run('cd {{release_path}} && {{bin/php}} artisan tinker --execute="Cache::put(\"health_check\", \"ok\", 60); echo Cache::get(\"health_check\");" 2>&1');

    if (trim($cacheCheck) !== 'ok') {
        warning('Cache health check returned unexpected result');
    } else {
        info('[OK] Cache working');
    }

    info('Deep health checks passed');
}

desc('Run health check with deep checks enabled');
task('deploy:verify:deep', function () {
    set('verify_deep_health', true);
    invoke('deploy:verify');
});

desc('Quick health check (status code only, no body check)');
task('deploy:verify:quick', function () {
    set('verify_check_body', false);
    set('verify_retries', 1);
    invoke('deploy:verify');
});
