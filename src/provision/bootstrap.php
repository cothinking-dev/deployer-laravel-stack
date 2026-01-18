<?php

namespace Deployer;

set('bootstrap_user', 'deployer');

// Specific sudo commands allowed for the deployer user
// This is more secure than NOPASSWD:ALL
// SECURITY: Wildcards are restricted to specific paths to prevent privilege escalation
set('sudo_allowed_commands', [
    // ─────────────────────────────────────────────────────────────────────
    // Service Management - PHP-FPM
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl restart php*-fpm',
    '/usr/bin/systemctl reload php*-fpm',
    '/usr/bin/systemctl start php*-fpm',
    '/usr/bin/systemctl stop php*-fpm',
    '/usr/bin/systemctl status php*-fpm',

    // ─────────────────────────────────────────────────────────────────────
    // Service Management - Caddy
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl restart caddy',
    '/usr/bin/systemctl reload caddy',
    '/usr/bin/systemctl start caddy',
    '/usr/bin/systemctl stop caddy',
    '/usr/bin/systemctl status caddy',
    '/usr/bin/caddy reload --config /etc/caddy/Caddyfile',
    '/usr/bin/caddy validate --config /etc/caddy/Caddyfile',
    '/usr/bin/caddy fmt --overwrite /etc/caddy/Caddyfile',
    '/usr/bin/caddy fmt --overwrite /etc/caddy/sites-enabled/*.conf',

    // ─────────────────────────────────────────────────────────────────────
    // Service Management - PostgreSQL
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl restart postgresql',
    '/usr/bin/systemctl reload postgresql',
    '/usr/bin/systemctl status postgresql',

    // ─────────────────────────────────────────────────────────────────────
    // Service Management - MySQL
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl restart mysql',
    '/usr/bin/systemctl reload mysql',
    '/usr/bin/systemctl status mysql',

    // ─────────────────────────────────────────────────────────────────────
    // Service Management - Redis
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl restart redis-server',
    '/usr/bin/systemctl reload redis-server',
    '/usr/bin/systemctl status redis-server',

    // ─────────────────────────────────────────────────────────────────────
    // Service Management - Fail2ban
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl restart fail2ban',
    '/usr/bin/systemctl status fail2ban',
    '/usr/bin/fail2ban-client status',
    '/usr/bin/fail2ban-client status sshd',

    // ─────────────────────────────────────────────────────────────────────
    // Service Management - FrankenPHP/Octane
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl restart frankenphp',
    '/usr/bin/systemctl reload frankenphp',
    '/usr/bin/systemctl status frankenphp',

    // ─────────────────────────────────────────────────────────────────────
    // Service Management - Supervisor
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/supervisorctl reread',
    '/usr/bin/supervisorctl update',
    '/usr/bin/supervisorctl restart *',
    '/usr/bin/supervisorctl status *',

    // ─────────────────────────────────────────────────────────────────────
    // Service Management - General
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl enable *',
    '/usr/bin/systemctl disable *',
    '/usr/bin/systemctl daemon-reload',

    // ─────────────────────────────────────────────────────────────────────
    // Package Management (necessary for provisioning)
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/apt-get update',
    '/usr/bin/apt-get install *',
    '/usr/bin/add-apt-repository *',
    '/usr/bin/gpg --dearmor -o /usr/share/keyrings/*',

    // ─────────────────────────────────────────────────────────────────────
    // Firewall (scoped to ufw)
    // ─────────────────────────────────────────────────────────────────────
    '/usr/sbin/ufw *',

    // ─────────────────────────────────────────────────────────────────────
    // File Operations - RESTRICTED PATHS ONLY
    // ─────────────────────────────────────────────────────────────────────

    // Caddy configuration
    '/bin/mv /tmp/Caddyfile /etc/caddy/Caddyfile',
    '/bin/mv /tmp/caddy-* /etc/caddy/',
    '/bin/mv /tmp/caddy-*.conf /etc/caddy/sites-enabled/',
    '/usr/bin/tee /etc/caddy/Caddyfile',
    '/usr/bin/tee /etc/caddy/sites-enabled/*.conf',
    '/usr/bin/touch /var/log/caddy/*.log',
    '/bin/mkdir -p /etc/caddy/sites-enabled',
    '/bin/mkdir -p /var/log/caddy',
    '/bin/chown -R caddy:caddy /var/log/caddy',
    '/bin/chmod 755 /var/log/caddy',

    // Fail2ban configuration
    '/bin/mv /tmp/jail.local /etc/fail2ban/jail.local',
    '/bin/mv /tmp/fail2ban-* /etc/fail2ban/',
    '/usr/bin/tee /etc/fail2ban/jail.local',

    // Supervisor configuration
    '/usr/bin/tee /etc/supervisor/conf.d/*.conf',

    // Systemd service files
    '/usr/bin/tee /etc/systemd/system/frankenphp.service',

    // PHP-FPM configuration
    '/usr/bin/tee /etc/php/*/fpm/pool.d/*.conf',
    '/bin/sed -i * /etc/php/*/fpm/php.ini',

    // Deploy path ownership (scoped to /home/deployer)
    '/bin/chown -R deployer:deployer /home/deployer/*',
    '/bin/chmod -R * /home/deployer/*',
    '/bin/chmod 755 /home/deployer',
    '/bin/mkdir -p /home/deployer/*',

    // Composer binary (specific target only)
    '/bin/mv /tmp/composer /usr/local/bin/composer',

    // ─────────────────────────────────────────────────────────────────────
    // Read-only operations (safe)
    // ─────────────────────────────────────────────────────────────────────
    '/bin/cat /etc/os-release',
    '/bin/cat /etc/php/*/fpm/php.ini',
    '/bin/cat /etc/caddy/Caddyfile',
    '/usr/bin/find /etc/php -name php.ini',
]);

desc('Bootstrap server: create deployer user with SSH keys and restricted sudo (can run as root or with sudo access)');
task('provision:bootstrap', function () {
    // Check if we need sudo for root operations
    $currentUser = run('whoami');
    $sudo = ($currentUser === 'root') ? '' : 'sudo ';

    $secrets = has('secrets') ? get('secrets') : [];
    $sudoPass = $secrets['sudo_pass'] ?? get('sudo_pass') ?? null;

    $user = get('bootstrap_user');
    $hostname = run('hostname');

    info("Creating user '{$user}'...");

    $userExists = run("id {$user} &>/dev/null && echo 'exists' || echo 'new'") === 'exists';

    if (! $userExists) {
        run("{$sudo}adduser --disabled-password --gecos '' {$user}");
        run("{$sudo}usermod -aG sudo {$user}");

        if ($sudoPass !== null) {
            info("Setting password for user '{$user}'...");
            $escapedPass = escapeshellarg($sudoPass);
            $passHash = run("printf '%s' {$escapedPass} | openssl passwd -stdin -6 2>/dev/null");
            run("{$sudo}usermod -p '{$passHash}' {$user}");
        } else {
            info("No sudo password configured - user will require NOPASSWD sudo rules");
        }
    } else {
        info("User '{$user}' already exists");

        if ($sudoPass !== null) {
            info("Updating password for existing user '{$user}'...");
            $escapedPass = escapeshellarg($sudoPass);
            $passHash = run("printf '%s' {$escapedPass} | openssl passwd -stdin -6 2>/dev/null");

            runWithRetry(
                "{$sudo}usermod -p '{$passHash}' {$user}",
                maxAttempts: 3,
                delaySeconds: 2,
                onFailure: fn() => warning("Password update failed - ensure NOPASSWD sudo is configured")
            );
        }

        run("{$sudo}usermod -aG sudo {$user} 2>/dev/null || true");
    }

    // Make home directory traversable by web servers (Caddy, PHP-FPM)
    run("{$sudo}chmod 755 /home/{$user}");

    info('Setting up SSH access...');

    run("{$sudo}mkdir -p /home/{$user}/.ssh");
    run("{$sudo}cp /root/.ssh/authorized_keys /home/{$user}/.ssh/authorized_keys 2>/dev/null || true");
    run("{$sudo}chown -R {$user}:{$user} /home/{$user}/.ssh");
    run("{$sudo}chmod 700 /home/{$user}/.ssh");
    run("{$sudo}chmod 600 /home/{$user}/.ssh/authorized_keys 2>/dev/null || true");

    // Generate SSH key for GitHub access (run as the deployer user)
    info('Generating SSH key for GitHub access...');
    $suCmd = ($currentUser === 'root') ? "su - {$user} -c" : "sudo -u {$user}";
    run("{$suCmd} \"ssh-keygen -t ed25519 -N '' -f /home/{$user}/.ssh/id_ed25519 -C '{$user}@{$hostname}' 2>/dev/null || true\"");

    info('Configuring restricted sudo access...');

    // Build sudoers file with specific allowed commands
    $sudoCommands = get('sudo_allowed_commands');
    $sudoersContent = "# Deployer user sudo rules - restricted to specific commands\n";
    $sudoersContent .= "# Generated by deployer-laravel-stack\n\n";

    foreach ($sudoCommands as $command) {
        $sudoersContent .= "{$user} ALL=(ALL) NOPASSWD: {$command}\n";
    }

    // PostgreSQL: allow running psql as postgres user
    $sudoersContent .= "\n# PostgreSQL commands (run as postgres user)\n";
    $sudoersContent .= "{$user} ALL=(postgres) NOPASSWD: /usr/bin/psql *\n";
    $sudoersContent .= "{$user} ALL=(postgres) NOPASSWD: /usr/bin/psql\n";
    $sudoersContent .= "{$user} ALL=(postgres) NOPASSWD: /usr/bin/createdb *\n";
    $sudoersContent .= "{$user} ALL=(postgres) NOPASSWD: /usr/bin/dropdb *\n";

    // Write sudoers file (using tee to handle both root and sudo scenarios)
    $escapedContent = escapeshellarg($sudoersContent);
    run("echo {$escapedContent} | {$sudo}tee /etc/sudoers.d/{$user} > /dev/null");
    run("{$sudo}chmod 440 /etc/sudoers.d/{$user}");

    // Validate sudoers file
    run("{$sudo}visudo -c -f /etc/sudoers.d/{$user}");

    $deployPath = get('deploy_path', '~/app');
    $expandedPath = str_replace('~', "/home/{$user}", $deployPath);
    run("{$sudo}mkdir -p {$expandedPath}");
    run("{$sudo}chown -R {$user}:{$user} {$expandedPath}");

    info("User '{$user}' created with SSH access and restricted sudo.");
    info("Deploy path '{$deployPath}' created.");

    // Show the generated SSH public key for GitHub
    $pubKey = run("cat /home/{$user}/.ssh/id_ed25519.pub 2>/dev/null || echo 'No key generated'");
    info("GitHub deploy key (add to repo settings):\n{$pubKey}");

    info("Now run: ./deploy/dep provision:all prod");
})->oncePerNode();

desc('Show current sudo rules for deployer user');
task('provision:sudo:show', function () {
    $user = get('bootstrap_user');
    $currentUser = run('whoami');
    $sudoPrefix = ($currentUser === 'root') ? '' : 'sudo ';

    $rules = run("{$sudoPrefix}cat /etc/sudoers.d/{$user} 2>/dev/null || echo 'No sudoers file found'");
    writeln($rules);
});

desc('Reset sudo rules to use full NOPASSWD:ALL (less secure, for debugging)');
task('provision:sudo:unrestrict', function () {
    $user = get('bootstrap_user');

    if (! askConfirmation("This will grant {$user} unrestricted sudo access. Continue?", false)) {
        info('Aborted.');

        return;
    }

    warning('Granting unrestricted sudo access...');
    $currentUser = run('whoami');
    $sudoPrefix = ($currentUser === 'root') ? '' : 'sudo ';

    run("echo '{$user} ALL=(ALL) NOPASSWD:ALL' | {$sudoPrefix}tee /etc/sudoers.d/{$user} > /dev/null");
    run("{$sudoPrefix}chmod 440 /etc/sudoers.d/{$user}");
    warning("Unrestricted sudo enabled for {$user}. Re-run provision:bootstrap to restore restrictions.");
});
