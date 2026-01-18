<?php

namespace Deployer;

// Default Octane configuration
// Note: For multi-environment single-server setups, override these per-environment:
//   set('octane_port', fn () => getStage() === 'prod' ? 8000 : 8001);
//   set('octane_admin_port', fn () => getStage() === 'prod' ? 2020 : 2021);
set('octane_port', 8000);
set('octane_admin_port', 2019);  // FrankenPHP's embedded Caddy admin API port
set('octane_workers', 'auto');
set('octane_max_requests', 500);

// Configurable health check endpoint (Laravel 11+ uses /up by default)
// Set to empty string to disable health check, or customize the path
set('octane_health_path', '/up');

// Service name can be overridden per-environment for multi-app servers
// Default: octane-{domain-with-dots-replaced-by-dashes}
// Example override: set('octane_service_name', 'octane-myapp-staging');
set('octane_service_name', null);

/**
 * Get the Octane systemd service name.
 * Uses 'octane_service_name' if set, otherwise derives from domain.
 */
function getOctaneServiceName(): string
{
    $serviceName = get('octane_service_name');
    if ($serviceName) {
        return $serviceName;
    }

    $domain = get('domain', 'app');

    return 'octane-'.str_replace('.', '-', $domain);
}

desc('Install FrankenPHP for Laravel Octane');
task('provision:octane', function () {
    info('Installing FrankenPHP for Laravel Octane...');

    // Download latest FrankenPHP binary
    $arch = run('uname -m');
    $archSuffix = match (trim($arch)) {
        'aarch64', 'arm64' => 'linux-aarch64',
        default => 'linux-x86_64',
    };

    info("Detected architecture: {$arch} -> {$archSuffix}");

    // Download FrankenPHP
    $downloadUrl = "https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-{$archSuffix}";
    run("curl -L -o /tmp/frankenphp {$downloadUrl}");

    // Install binary
    sudo('mv /tmp/frankenphp /usr/local/bin/frankenphp');
    sudo('chmod +x /usr/local/bin/frankenphp');

    // Verify installation
    $version = run('frankenphp version 2>/dev/null || echo "unknown"');
    info("FrankenPHP installed: {$version}");

    // Create systemd service
    invoke('octane:service');
});

desc('Create systemd service for Laravel Octane');
task('octane:service', function () {
    $deployPath = get('deploy_path');
    $homePath = run('echo $HOME');
    $fullPath = str_replace('~', $homePath, $deployPath);
    $port = get('octane_port', 8000);
    $adminPort = get('octane_admin_port', 2019);
    $workers = get('octane_workers', 'auto');
    $maxRequests = get('octane_max_requests', 500);

    // Get service name (configurable or derived from domain)
    $serviceName = getOctaneServiceName();

    // Check if port is already in use by another service
    $portCheck = run("ss -tlnp 2>/dev/null | grep ':{$port} ' || echo ''");
    if (! empty(trim($portCheck))) {
        warning("Port {$port} is already in use:");
        writeln($portCheck);
        warning("Consider setting a different 'octane_port' for this environment.");
        warning("Common ports: 8000 (prod), 8001 (staging), 8002, 8003, etc.");

        // Don't fail, but warn the user
    }

    info("Creating systemd service: {$serviceName} (port: {$port}, admin: {$adminPort})");

    // Build ReadWritePaths for all shared directories that need write access
    $readWritePaths = [
        "{$fullPath}/shared/storage",
        '/tmp',
    ];

    // Add SQLite database directory if using SQLite
    $dbConnection = get('db_connection', 'pgsql');
    if ($dbConnection === 'sqlite') {
        $readWritePaths[] = "{$fullPath}/shared/database";
    }

    // Add custom storage link directories
    $storageLinks = get('storage_links', []);
    foreach ($storageLinks as $sharedPath) {
        $readWritePaths[] = "{$fullPath}/shared/{$sharedPath}";
    }

    // Add any additional shared_dirs that might need write access
    $sharedDirs = get('shared_dirs', []);
    foreach ($sharedDirs as $dir) {
        // Skip storage (already included) and bootstrap/cache (part of release)
        if (!in_array($dir, ['storage', 'bootstrap/cache'])) {
            $path = "{$fullPath}/shared/{$dir}";
            if (!in_array($path, $readWritePaths)) {
                $readWritePaths[] = $path;
            }
        }
    }

    $readWritePathsConfig = implode("\n", array_map(
        fn ($path) => "ReadWritePaths={$path}",
        array_unique($readWritePaths)
    ));

    $domain = get('domain', 'app');
    $service = <<<SERVICE
[Unit]
Description=Laravel Octane ({$domain})
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory={$fullPath}/current
ExecStart=/usr/local/bin/frankenphp php-server --listen 127.0.0.1:{$port} --admin-listen 127.0.0.1:{$adminPort} --worker ./public/index.php
ExecReload=/bin/kill -USR1 \$MAINPID
Restart=always
RestartSec=3

# Environment
Environment="APP_ENV=production"
Environment="LARAVEL_OCTANE=1"

# Security hardening
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
{$readWritePathsConfig}

[Install]
WantedBy=multi-user.target
SERVICE;

    // Write service file
    $tmpFile = "/tmp/{$serviceName}.service";
    run("cat > {$tmpFile} << 'EOF'\n{$service}\nEOF");
    sudo("mv {$tmpFile} /etc/systemd/system/{$serviceName}.service");
    sudo('systemctl daemon-reload');
    sudo("systemctl enable {$serviceName}");

    info("Service {$serviceName} created and enabled");

    // Set up sudoers for passwordless service management
    invoke('provision:octane:sudoers');
});

desc('Configure sudoers for Octane service management');
task('provision:octane:sudoers', function () {
    $serviceName = getOctaneServiceName();
    $deployUser = get('remote_user', 'deployer');

    // If running as root, set up sudoers for deployer user
    if ($deployUser === 'root') {
        $deployUser = 'deployer';
    }

    info("Configuring sudoers for {$deployUser} to manage {$serviceName}...");

    // Create a sudoers file for this specific Octane service
    $sudoersFile = "/etc/sudoers.d/octane-{$serviceName}";
    $sudoersContent = <<<SUDOERS
# Allow {$deployUser} to manage the {$serviceName} service without password
# Generated by deployer-laravel-stack
{$deployUser} ALL=(ALL) NOPASSWD: /usr/bin/systemctl start {$serviceName}
{$deployUser} ALL=(ALL) NOPASSWD: /usr/bin/systemctl stop {$serviceName}
{$deployUser} ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart {$serviceName}
{$deployUser} ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload {$serviceName}
{$deployUser} ALL=(ALL) NOPASSWD: /usr/bin/systemctl status {$serviceName}
SUDOERS;

    // Write sudoers file
    $tmpFile = "/tmp/sudoers-{$serviceName}";
    run("cat > {$tmpFile} << 'EOF'\n{$sudoersContent}\nEOF");

    // Validate and install sudoers file
    sudo("visudo -c -f {$tmpFile}");
    sudo("mv {$tmpFile} {$sudoersFile}");
    sudo("chmod 440 {$sudoersFile}");

    info("Sudoers configured: {$deployUser} can now manage {$serviceName} without password");
});

desc('Start Laravel Octane');
task('octane:start', function () {
    $serviceName = getOctaneServiceName();

    sudo("systemctl start {$serviceName}");
    info("Octane started: {$serviceName}");
});

desc('Stop Laravel Octane');
task('octane:stop', function () {
    $serviceName = getOctaneServiceName();

    sudo("systemctl stop {$serviceName}");
    info("Octane stopped: {$serviceName}");
});

desc('Restart Laravel Octane');
task('octane:restart', function () {
    $serviceName = getOctaneServiceName();

    sudo("systemctl restart {$serviceName}");
    info("Octane restarted: {$serviceName}");
});

desc('Reload Laravel Octane workers');
task('octane:reload', function () {
    $serviceName = getOctaneServiceName();

    // Check if service exists and is running
    $status = run("systemctl is-active {$serviceName} 2>/dev/null || echo 'inactive'");

    if (trim($status) === 'active') {
        sudo("systemctl reload {$serviceName}");
        info("Octane workers reloaded: {$serviceName}");
    } else {
        warning("Octane service not running: {$serviceName}");
    }
});

desc('Show Laravel Octane status');
task('octane:status', function () {
    $serviceName = getOctaneServiceName();

    $status = sudo("systemctl status {$serviceName} --no-pager -l 2>/dev/null || echo 'Service not found'");
    writeln($status);

    // Check port
    $port = get('octane_port', 8000);
    $portCheck = run("ss -tlnp | grep :{$port} || echo 'Port {$port} not listening'");
    writeln($portCheck);
});

desc('Show Octane logs');
task('octane:logs', function () {
    $serviceName = getOctaneServiceName();

    $logs = sudo("journalctl -u {$serviceName} -n 50 --no-pager");
    writeln($logs);
});

desc('Check Octane health');
task('octane:health', function () {
    $port = get('octane_port', 8000);
    $healthPath = get('octane_health_path', '/up');

    // If health path is empty, skip the check
    if (empty($healthPath)) {
        info('Octane health check: skipped (octane_health_path is empty)');

        return;
    }

    $response = run("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:{$port}{$healthPath} 2>/dev/null || echo '000'");

    if (trim($response) === '200') {
        info('Octane health check: OK');
    } else {
        warning("Octane health check failed: HTTP {$response} (endpoint: {$healthPath})");
        warning("If your app doesn't have a {$healthPath} route, set octane_health_path to '/' or ''");
    }
});
