<?php

/**
 * HTTPS & Proxy Configuration Tasks
 *
 * When Laravel apps run behind reverse proxies (Cloudflare, Caddy, nginx),
 * they often don't detect HTTPS correctly. This causes:
 * - Mixed content errors (assets load via http:// on https:// pages)
 * - Broken Filament admin forms (JS components fail to load)
 * - Insecure redirect loops
 *
 * Solutions:
 * 1. URL::forceScheme('https') in AppServiceProvider::boot()
 * 2. TrustProxies middleware in bootstrap/app.php
 * 3. APP_URL=https://... in production .env
 */

namespace Deployer;

// Whether to check for HTTPS configuration issues
set('https_check_enabled', true);

// Whether to check for Filament assets
set('filament_check_enabled', true);

// Local paths to check for Filament assets
set('filament_asset_paths', [
    'public/js/filament',
    'public/css/filament',
]);

desc('Check local codebase for HTTPS/proxy configuration');
task('check:https-config', function () {
    if (! get('https_check_enabled', true)) {
        return;
    }

    $warnings = [];
    $errors = [];

    info('Checking HTTPS/proxy configuration...');

    // Check for URL::forceScheme in AppServiceProvider
    $appServiceProvider = runLocally('cat app/Providers/AppServiceProvider.php 2>/dev/null || echo ""');

    if (! str_contains($appServiceProvider, 'URL::forceScheme')) {
        $warnings[] = "URL::forceScheme('https') not found in AppServiceProvider";
        $warnings[] = '  -> This causes mixed content errors behind proxies (Cloudflare/Caddy/nginx)';
        $warnings[] = '  -> Add to boot() method:';
        $warnings[] = "     if (\$this->app->environment('production')) {";
        $warnings[] = "         \\Illuminate\\Support\\Facades\\URL::forceScheme('https');";
        $warnings[] = '     }';
    }

    // Check for TrustProxies in bootstrap/app.php
    $bootstrapApp = runLocally('cat bootstrap/app.php 2>/dev/null || echo ""');

    if (! str_contains($bootstrapApp, 'trustProxies')) {
        $warnings[] = 'TrustProxies middleware not configured in bootstrap/app.php';
        $warnings[] = '  -> Add to withMiddleware():';
        $warnings[] = '     $middleware->trustProxies(at: \'*\', headers: Request::HEADER_X_FORWARDED_FOR |';
        $warnings[] = '         Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT |';
        $warnings[] = '         Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB);';
    }

    // Report results
    if (count($warnings) > 0) {
        writeln('');
        foreach ($warnings as $warning) {
            writeln("<fg=yellow>  ⚠ WARNING:</> {$warning}");
        }
        writeln('');
    }

    if (count($errors) > 0) {
        foreach ($errors as $error) {
            writeln("<fg=red>  ✗ ERROR:</> {$error}");
        }
        throw new \RuntimeException('HTTPS configuration check failed');
    }

    if (empty($warnings) && empty($errors)) {
        info('[OK] HTTPS/proxy configuration looks good');
    }
});

desc('Check local Filament assets exist');
task('check:filament-assets', function () {
    if (! get('filament_check_enabled', true)) {
        return;
    }

    $paths = get('filament_asset_paths', []);
    $missing = [];

    info('Checking Filament assets...');

    foreach ($paths as $path) {
        if (! testLocally("[ -d {$path} ]")) {
            $missing[] = $path;
        }
    }

    if (! empty($missing)) {
        writeln('');
        writeln('<fg=red>  ✗ ERROR:</> Filament assets not found:');
        foreach ($missing as $path) {
            writeln("     - {$path}");
        }
        writeln('');
        writeln('  -> Run: php artisan filament:assets');
        writeln('  -> Without these, admin forms will be broken (select, file-upload, markdown editor)');
        writeln('');

        throw new \RuntimeException('Filament assets missing. Run: php artisan filament:assets');
    }

    info('[OK] Filament assets exist');
});

desc('Verify HTTPS redirects work correctly on deployed site');
task('verify:https-redirects', function () {
    $url = get('url', get('verify_url', ''));

    if (empty($url)) {
        warning('No URL configured, skipping HTTPS redirect check');

        return;
    }

    info('Checking HTTPS redirects...');

    // Check if redirects use HTTPS
    $headers = runLocally("curl -sI {$url}/admin 2>/dev/null | head -20 || echo ''");

    if (str_contains($headers, 'location: http://') && ! str_contains($headers, 'location: https://')) {
        writeln('');
        writeln('<fg=red>  ✗ ERROR:</> Redirects using HTTP instead of HTTPS');
        writeln("  -> Check URL::forceScheme('https') in AppServiceProvider");
        writeln('  -> Check TrustProxies middleware in bootstrap/app.php');
        writeln('  -> Check APP_URL in production .env uses https://');
        writeln('');

        throw new \RuntimeException('HTTPS redirect check failed - redirects using HTTP');
    }

    info('[OK] Redirects use HTTPS');
});

desc('Verify Filament assets are accessible on deployed site');
task('verify:filament-assets', function () {
    $url = get('url', get('verify_url', ''));

    if (empty($url)) {
        warning('No URL configured, skipping Filament assets check');

        return;
    }

    info('Checking Filament assets accessibility...');

    $assets = [
        '/js/filament/support/support.js' => 'Filament JS',
        '/css/filament/support/support.css' => 'Filament CSS',
    ];

    $errors = [];

    foreach ($assets as $path => $name) {
        $assetUrl = rtrim($url, '/') . $path;
        $status = runLocally("curl -s -o /dev/null -w '%{http_code}' '{$assetUrl}' 2>/dev/null || echo '000'");

        if ($status !== '200') {
            $errors[] = "{$name} not accessible (HTTP {$status}): {$assetUrl}";
        }
    }

    if (! empty($errors)) {
        writeln('');
        foreach ($errors as $error) {
            writeln("<fg=red>  ✗ ERROR:</> {$error}");
        }
        writeln('');
        writeln('  -> Admin forms will be broken (select, file-upload, markdown editor)');
        writeln('  -> Run: php artisan filament:assets');
        writeln('  -> Ensure assets are deployed to public/js/filament and public/css/filament');
        writeln('');

        throw new \RuntimeException('Filament assets not accessible');
    }

    info('[OK] Filament assets accessible');
});

desc('Verify storage symlink points to shared storage');
task('verify:storage-symlink', function () {
    info('Checking storage symlink...');

    $storageLinkTarget = run("readlink {{deploy_path}}/current/public/storage 2>/dev/null || echo ''");

    if (empty($storageLinkTarget)) {
        writeln('');
        writeln('<fg=red>  ✗ ERROR:</> Storage symlink does not exist');
        writeln('  -> Run storage:link-custom task or configure storage_links');
        writeln('');

        throw new \RuntimeException('Storage symlink missing');
    }

    if (! str_contains($storageLinkTarget, 'shared/storage') && ! str_contains($storageLinkTarget, 'shared')) {
        writeln('');
        writeln("<fg=red>  ✗ ERROR:</> Storage symlink points to wrong location: {$storageLinkTarget}");
        writeln('  -> Should point to: shared/storage/app/public');
        writeln('  -> Using artisan storage:link with Deployer releases causes uploaded files');
        writeln('     to be lost on each deploy because it symlinks to release storage.');
        writeln('  -> Use storage:link-custom task or configure storage_links setting.');
        writeln('');

        throw new \RuntimeException('Storage symlink points to release storage instead of shared storage');
    }

    info("[OK] Storage symlink: {$storageLinkTarget}");
});

desc('Test uploaded files are accessible');
task('verify:storage-files', function () {
    $url = get('url', get('verify_url', ''));

    if (empty($url)) {
        warning('No URL configured, skipping storage files check');

        return;
    }

    info('Checking storage files accessibility...');

    // Find a real file in shared storage
    $testFile = run("find {{deploy_path}}/shared/storage/app/public -type f ! -name '.gitignore' 2>/dev/null | head -1 || echo ''");

    if (empty($testFile)) {
        info('[SKIP] No files in storage to test');

        return;
    }

    // Extract relative path
    $relativePath = run("echo '{$testFile}' | sed 's|.*/shared/storage/app/public/||'");
    $storageUrl = rtrim($url, '/') . '/storage/' . trim($relativePath);

    $status = runLocally("curl -s -o /dev/null -w '%{http_code}' '{$storageUrl}' 2>/dev/null || echo '000'");

    if ($status === '404') {
        writeln('');
        writeln("<fg=red>  ✗ ERROR:</> Storage file returning 404: {$storageUrl}");
        writeln('  -> Check storage symlink points to shared storage');
        writeln('  -> Check web server configuration serves /storage correctly');
        writeln('');

        throw new \RuntimeException('Storage files returning 404');
    }

    info("[OK] Storage files accessible (tested: {$relativePath})");
});

desc('Run all HTTPS and asset verification checks');
task('verify:https-all', [
    'verify:https-redirects',
    'verify:filament-assets',
    'verify:storage-symlink',
    'verify:storage-files',
]);

desc('Check .env has correct HTTPS configuration');
task('check:env-https', function () {
    $sharedEnv = '{{deploy_path}}/shared/.env';

    if (! test("[ -f {$sharedEnv} ]")) {
        warning('Shared .env not found, skipping check');

        return;
    }

    info('Checking .env HTTPS configuration...');

    $envContent = run("cat {$sharedEnv}");
    $errors = [];

    // Check APP_URL
    if (preg_match('/^APP_URL=(.+)$/m', $envContent, $matches)) {
        $appUrl = trim($matches[1]);
        if (! str_starts_with($appUrl, 'https://')) {
            $errors[] = "APP_URL must use HTTPS. Current: {$appUrl}";
        }
    } else {
        $errors[] = 'APP_URL is not set in .env';
    }

    if (! empty($errors)) {
        writeln('');
        foreach ($errors as $error) {
            writeln("<fg=red>  ✗ ERROR:</> {$error}");
        }
        writeln('');

        throw new \RuntimeException('Environment HTTPS configuration check failed');
    }

    info('[OK] .env APP_URL uses HTTPS');
});
