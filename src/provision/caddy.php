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

    sudo('systemctl enable caddy');

    info('Caddy installed (run caddy:configure to set up your domain)');
});

desc('Configure Caddy for the application domain');
task('caddy:configure', function () {
    $domain = get('domain');
    $deployPath = get('deploy_path');
    $phpVersion = get('php_version', '8.4');

    if (! $domain) {
        throw new \RuntimeException('domain option is required for Caddy configuration');
    }

    $homePath = run('echo $HOME');
    $fullPath = str_replace('~', $homePath, $deployPath);

    info("Configuring Caddy for: {$domain}");

    $caddyfile = <<<CADDY
{$domain} {
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

    sudo('mkdir -p /var/log/caddy');
    sudo('chown caddy:caddy /var/log/caddy');

    run('echo ' . escapeshellarg($caddyfile) . ' > /tmp/Caddyfile');
    sudo('mv /tmp/Caddyfile /etc/caddy/Caddyfile');
    sudo('caddy fmt --overwrite /etc/caddy/Caddyfile');
    sudo('systemctl restart caddy');

    info("Caddy configured for: {$domain}");
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
