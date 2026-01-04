<?php

namespace Deployer;

desc('Install Caddy web server');
task('provision:caddy', function () {
    info('Installing Caddy...');

    sudo('apt-get update');
    sudo('apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl');

    run("curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o /tmp/caddy-gpg.key");
    sudo('gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg --yes /tmp/caddy-gpg.key');
    run("curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' -o /tmp/caddy-stable.list");
    sudo('mv /tmp/caddy-stable.list /etc/apt/sources.list.d/caddy-stable.list');

    sudo('apt-get update');
    sudo('apt-get install -y caddy');

    run('rm -f /tmp/caddy-gpg.key');

    sudo('mkdir -p /etc/caddy/sites-enabled');
    sudo('mkdir -p /var/log/caddy');
    sudo('chown -R caddy:caddy /var/log/caddy');
    sudo('chmod 755 /var/log/caddy');

    $mainCaddyfile = <<<'CADDY'
import /etc/caddy/sites-enabled/*
CADDY;

    run('echo ' . escapeshellarg($mainCaddyfile) . ' > /tmp/Caddyfile');
    sudo('mv /tmp/Caddyfile /etc/caddy/Caddyfile');

    sudo('systemctl enable caddy');
    sudo('systemctl restart caddy');

    info('Caddy installed (run caddy:configure to set up your domain)');
});

desc('Configure Caddy for the application domain');
task('caddy:configure', function () {
    $domain = get('domain');
    $deployPath = get('deploy_path');
    $phpVersion = get('php_version', '8.4');
    // tls_mode: 'auto' (Let's Encrypt), 'internal' (self-signed, for Cloudflare proxy), or custom cert path
    $tlsMode = get('tls_mode', 'auto');

    if (! $domain) {
        throw new \RuntimeException('domain option is required for Caddy configuration');
    }

    $homePath = run('echo $HOME');
    $fullPath = str_replace('~', $homePath, $deployPath);
    $safeDomain = str_replace('.', '-', $domain);

    info("Configuring Caddy for: {$domain} (TLS: {$tlsMode})");

    // Build TLS directive
    $tlsDirective = '';
    if ($tlsMode === 'internal') {
        $tlsDirective = '    tls internal';
    } elseif ($tlsMode !== 'auto' && ! empty($tlsMode)) {
        // Custom cert path
        $tlsDirective = "    tls {$tlsMode}";
    }

    $siteConfig = <<<CADDY
{$domain} {
{$tlsDirective}
    root * {$fullPath}/current/public
    encode gzip

    php_fastcgi unix//var/run/php/php{$phpVersion}-fpm.sock
    file_server

    header {
        X-Content-Type-Options "nosniff"
        X-Frame-Options "SAMEORIGIN"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "geolocation=(), microphone=(), camera=()"
        -Server
    }

    try_files {path} {path}/ /index.php?{query}

    log {
        output file /var/log/caddy/{$domain}.log
        format json
    }
}
CADDY;

    sudo('mkdir -p /etc/caddy/sites-enabled');

    $tmpFile = "/tmp/caddy-{$safeDomain}.conf";
    run('echo ' . escapeshellarg($siteConfig) . " > {$tmpFile}");
    sudo("mv {$tmpFile} /etc/caddy/sites-enabled/{$safeDomain}.conf");
    sudo("caddy fmt --overwrite /etc/caddy/sites-enabled/{$safeDomain}.conf");

    $mainCaddyfile = trim(sudo('cat /etc/caddy/Caddyfile 2>/dev/null || echo ""'));
    if (strpos($mainCaddyfile, 'import /etc/caddy/sites-enabled/*') === false) {
        $newCaddyfile = "import /etc/caddy/sites-enabled/*\n" . $mainCaddyfile;
        run('echo ' . escapeshellarg($newCaddyfile) . ' > /tmp/Caddyfile');
        sudo('mv /tmp/Caddyfile /etc/caddy/Caddyfile');
    }

    // Pre-create log file with correct ownership to avoid permission errors
    sudo("touch /var/log/caddy/{$domain}.log");
    sudo("chown caddy:caddy /var/log/caddy/{$domain}.log");

    sudo('caddy validate --config /etc/caddy/Caddyfile 2>&1 || true');
    sudo('systemctl reload caddy');

    info("Caddy configured for: {$domain}");
});

desc('Remove a site from Caddy');
task('caddy:remove-site', function () {
    $domain = get('domain');

    if (! $domain) {
        throw new \RuntimeException('domain option is required');
    }

    $safeDomain = str_replace('.', '-', $domain);

    info("Removing Caddy site: {$domain}");

    sudo("rm -f /etc/caddy/sites-enabled/{$safeDomain}.conf");
    sudo('systemctl reload caddy');

    info("Removed: {$domain}");
});

desc('List configured Caddy sites');
task('caddy:list-sites', function () {
    $result = sudo('ls -la /etc/caddy/sites-enabled/ 2>/dev/null || echo "No sites configured"');
    writeln($result);
});

desc('Reload Caddy configuration');
task('caddy:reload', function () {
    sudo('systemctl reload caddy');
    info('Caddy configuration reloaded');
});

desc('Show Caddy status');
task('caddy:status', function () {
    $status = sudo('systemctl status caddy --no-pager -l');
    writeln($status);
});

desc('Show Caddy access logs');
task('caddy:logs', function () {
    $domain = get('domain', '*');
    sudo("tail -50 /var/log/caddy/{$domain}.log 2>/dev/null || echo 'No logs found'");
});

desc('Validate Caddyfile syntax');
task('caddy:validate', function () {
    $result = sudo('caddy validate --config /etc/caddy/Caddyfile 2>&1');
    writeln($result);
});
