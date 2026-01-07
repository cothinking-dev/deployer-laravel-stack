<?php

namespace Deployer;

set('bootstrap_user', 'deployer');

// Specific sudo commands allowed for the deployer user
// This is more secure than NOPASSWD:ALL
set('sudo_allowed_commands', [
    // PHP-FPM management
    '/usr/bin/systemctl restart php*-fpm',
    '/usr/bin/systemctl reload php*-fpm',
    '/usr/bin/systemctl start php*-fpm',
    '/usr/bin/systemctl stop php*-fpm',
    '/usr/bin/systemctl status php*-fpm',

    // Caddy management
    '/usr/bin/systemctl restart caddy',
    '/usr/bin/systemctl reload caddy',
    '/usr/bin/systemctl start caddy',
    '/usr/bin/systemctl stop caddy',
    '/usr/bin/systemctl status caddy',
    '/usr/bin/caddy *',

    // PostgreSQL management
    '/usr/bin/systemctl restart postgresql',
    '/usr/bin/systemctl reload postgresql',
    '/usr/bin/systemctl status postgresql',

    // Redis management
    '/usr/bin/systemctl restart redis-server',
    '/usr/bin/systemctl reload redis-server',
    '/usr/bin/systemctl status redis-server',

    // Fail2ban management
    '/usr/bin/systemctl restart fail2ban',
    '/usr/bin/systemctl status fail2ban',
    '/usr/bin/fail2ban-client *',

    // Supervisor management (for queue workers)
    '/usr/bin/supervisorctl *',

    // Package management (for provisioning)
    '/usr/bin/apt-get update',
    '/usr/bin/apt-get install *',
    '/usr/bin/add-apt-repository *',

    // Firewall management
    '/usr/sbin/ufw *',

    // File operations for deployment
    '/bin/chown *',
    '/bin/chmod *',
    '/bin/mkdir *',
    '/bin/mv /tmp/* /etc/caddy/*',
    '/bin/mv /tmp/* /etc/fail2ban/*',
    '/bin/mv * /usr/local/bin/*',
    '/usr/bin/touch /var/log/caddy/*',
    '/usr/bin/tee *',

    // GPG for package signing
    '/usr/bin/gpg *',

    // Bash for scripts
    '/bin/bash /tmp/*.sh',

    // Sed for config modifications
    '/bin/sed *',

    // Find for locating config files
    '/usr/bin/find *',

    // Cat for reading system files
    '/bin/cat /etc/*',

    // Systemctl for general service management
    '/usr/bin/systemctl enable *',
    '/usr/bin/systemctl disable *',
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

            $maxRetries = 3;
            $delay = 2;
            $success = false;

            for ($i = 0; $i < $maxRetries; $i++) {
                try {
                    if ($i > 0) {
                        info("Retrying password update (attempt " . ($i + 1) . "/{$maxRetries})...");
                        run("sleep {$delay}");
                    }

                    run("{$sudo}usermod -p '{$passHash}' {$user}");
                    $success = true;
                    break;
                } catch (\Throwable $e) {
                    if ($i === $maxRetries - 1) {
                        warning("Failed to update password after {$maxRetries} attempts: {$e->getMessage()}");
                        warning("Continuing without password update - ensure NOPASSWD sudo is configured");
                    }
                    $delay *= 2;
                }
            }
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
