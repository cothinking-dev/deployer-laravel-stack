<?php

namespace Deployer;

set('fail2ban_ssh_maxretry', 5);
set('fail2ban_ssh_bantime', '1h');
set('fail2ban_ssh_findtime', '10m');

desc('Install and configure Fail2ban for SSH/HTTP brute force protection');
task('provision:fail2ban', function () {
    info('Installing Fail2ban...');

    sudo('apt-get update');
    sudo('apt-get install -y fail2ban');

    $maxRetry = get('fail2ban_ssh_maxretry');
    $banTime = get('fail2ban_ssh_bantime');
    $findTime = get('fail2ban_ssh_findtime');

    $jailLocal = <<<EOF
[DEFAULT]
bantime = {$banTime}
findtime = {$findTime}
maxretry = {$maxRetry}
banaction = ufw

[sshd]
enabled = true
port = ssh
filter = sshd
logpath = /var/log/auth.log
maxretry = {$maxRetry}

[caddy-auth]
enabled = true
port = http,https
filter = caddy-auth
logpath = /var/log/caddy/*.log
maxretry = 10
findtime = 1m
bantime = 15m
EOF;

    $caddyFilter = <<<'EOF'
[Definition]
failregex = ^.*"remote_ip":"<HOST>".*"status":(401|403|429).*$
ignoreregex =
EOF;

    run('echo ' . escapeshellarg($jailLocal) . ' | sudo tee /etc/fail2ban/jail.local > /dev/null');
    run('sudo mkdir -p /etc/fail2ban/filter.d');
    run('echo ' . escapeshellarg($caddyFilter) . ' | sudo tee /etc/fail2ban/filter.d/caddy-auth.conf > /dev/null');

    sudo('systemctl enable fail2ban');
    sudo('systemctl restart fail2ban');

    info("Fail2ban configured: SSH ({$maxRetry} attempts / {$findTime}), Caddy auth errors");
});

desc('Show Fail2ban status and banned IPs');
task('fail2ban:status', function () {
    $status = sudo('fail2ban-client status');
    writeln($status);

    $sshStatus = run('sudo fail2ban-client status sshd 2>/dev/null || echo "sshd jail not active"');
    writeln("\nSSH Jail:\n" . $sshStatus);

    $caddyStatus = run('sudo fail2ban-client status caddy-auth 2>/dev/null || echo "caddy-auth jail not active"');
    writeln("\nCaddy Auth Jail:\n" . $caddyStatus);
});

desc('Unban an IP address from all jails');
task('fail2ban:unban', function () {
    $ip = ask('IP address to unban:');

    if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new \RuntimeException('Invalid IP address');
    }

    run("sudo fail2ban-client unban {$ip} 2>/dev/null || true");
    info("Unbanned IP: {$ip}");
});

desc('Show recent Fail2ban log entries');
task('fail2ban:logs', function () {
    $logs = run('sudo tail -50 /var/log/fail2ban.log 2>/dev/null || echo "No log file found"');
    writeln($logs);
});
