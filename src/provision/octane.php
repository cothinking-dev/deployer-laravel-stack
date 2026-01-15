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

    // Determine app slug for service name
    $domain = get('domain', 'app');
    $serviceName = 'octane-' . str_replace('.', '-', $domain);

    info("Creating systemd service: {$serviceName} (port: {$port}, admin: {$adminPort})");

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
ReadWritePaths={$fullPath}/shared/storage
ReadWritePaths=/tmp

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
});

desc('Start Laravel Octane');
task('octane:start', function () {
    $domain = get('domain', 'app');
    $serviceName = 'octane-' . str_replace('.', '-', $domain);

    sudo("systemctl start {$serviceName}");
    info("Octane started: {$serviceName}");
});

desc('Stop Laravel Octane');
task('octane:stop', function () {
    $domain = get('domain', 'app');
    $serviceName = 'octane-' . str_replace('.', '-', $domain);

    sudo("systemctl stop {$serviceName}");
    info("Octane stopped: {$serviceName}");
});

desc('Restart Laravel Octane');
task('octane:restart', function () {
    $domain = get('domain', 'app');
    $serviceName = 'octane-' . str_replace('.', '-', $domain);

    sudo("systemctl restart {$serviceName}");
    info("Octane restarted: {$serviceName}");
});

desc('Reload Laravel Octane workers');
task('octane:reload', function () {
    $domain = get('domain', 'app');
    $serviceName = 'octane-' . str_replace('.', '-', $domain);

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
    $domain = get('domain', 'app');
    $serviceName = 'octane-' . str_replace('.', '-', $domain);

    $status = sudo("systemctl status {$serviceName} --no-pager -l 2>/dev/null || echo 'Service not found'");
    writeln($status);

    // Check port
    $port = get('octane_port', 8000);
    $portCheck = run("ss -tlnp | grep {$port} || echo 'Port {$port} not listening'");
    writeln($portCheck);
});

desc('Show Octane logs');
task('octane:logs', function () {
    $domain = get('domain', 'app');
    $serviceName = 'octane-' . str_replace('.', '-', $domain);

    $logs = sudo("journalctl -u {$serviceName} -n 50 --no-pager");
    writeln($logs);
});

desc('Check Octane health');
task('octane:health', function () {
    $port = get('octane_port', 8000);

    $response = run("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:{$port}/up 2>/dev/null || echo '000'");

    if (trim($response) === '200') {
        info('Octane health check: OK');
    } else {
        warning("Octane health check failed: HTTP {$response}");
    }
});
