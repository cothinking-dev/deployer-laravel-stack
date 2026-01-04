<?php

namespace Deployer;

set('verify_url', function () {
    return get('url');
});

set('verify_valid_codes', ['200', '301', '302', '303', '307', '308']);
set('verify_timeout', 15);
set('verify_wait', 2);

desc('Verify deployment health via HTTP check');
task('deploy:verify', function () {
    $url = get('verify_url');
    $stage = getStage();
    $validCodes = get('verify_valid_codes');
    $timeout = get('verify_timeout');
    $wait = get('verify_wait');

    if (empty($url)) {
        warning('No URL configured for verification (set verify_url or url)');

        return;
    }

    info("Verifying deployment for {$stage}...");

    if ($wait > 0) {
        run("sleep {$wait}");
    }

    $checkUrl = rtrim($url, '/');
    $result = runLocally("curl -s -o /dev/null -w '%{http_code}' -L --max-time {$timeout} --insecure {$checkUrl} 2>/dev/null || echo '000'");
    $statusCode = trim($result);

    if (! in_array($statusCode, $validCodes)) {
        warning("Health check returned HTTP {$statusCode}");
        run('tail -20 {{deploy_path}}/shared/storage/logs/laravel.log 2>/dev/null || true');

        throw new \RuntimeException("Deployment verification failed: HTTP {$statusCode}");
    }

    info("Health check passed (HTTP {$statusCode})");
});
