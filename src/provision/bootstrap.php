<?php

namespace Deployer;

set('bootstrap_user', 'deployer');

// Path to the deploy wrapper script (for command= restriction)
set('deploy_wrapper_path', '/usr/local/bin/deployer-shell');

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

function installDeployerShell(string $path): void
{
    $script = <<<'BASH'
#!/bin/bash
# Restricted shell for automated deployments (CI/CD)
# Only allows commands passed via SSH_ORIGINAL_COMMAND
set -euo pipefail

if [[ -z "${SSH_ORIGINAL_COMMAND:-}" ]]; then
    echo "Interactive sessions not allowed with this key." >&2
    echo "Use an admin key for shell access." >&2
    exit 1
fi

exec /bin/bash -c "$SSH_ORIGINAL_COMMAND"
BASH;

    run("echo " . escapeshellarg($script) . " > {$path}");
    run("chmod 755 {$path}");
}

desc('Bootstrap server: create deployer user with SSH keys and restricted sudo (run as root)');
task('provision:bootstrap', function () {
    $secrets = has('secrets') ? get('secrets') : [];
    $sudoPass = $secrets['sudo_pass'] ?? get('sudo_pass') ?? null;

    if ($sudoPass === null) {
        throw new \RuntimeException('No sudo password configured for deployer user.');
    }

    $user = get('bootstrap_user');
    $hostname = run('hostname');

    info("Creating user '{$user}'...");

    $userExists = run("id {$user} &>/dev/null && echo 'exists' || echo 'new'") === 'exists';

    if (! $userExists) {
        run("adduser --disabled-password --gecos '' {$user}");
    }

    info("Setting password for user '{$user}'...");

    $passHash = run("echo '%secret%' | openssl passwd -stdin -6", secret: $sudoPass);
    run("usermod -p '{$passHash}' {$user}");
    run("usermod -aG sudo {$user}");

    // Make home directory traversable by web servers (Caddy, PHP-FPM)
    run("chmod 755 /home/{$user}");

    info('Setting up SSH access...');

    run("mkdir -p /home/{$user}/.ssh");

    $sshDir = "/home/{$user}/.ssh";
    $authorizedKeysFile = "{$sshDir}/authorized_keys";

    run("touch {$authorizedKeysFile}");

    $deployKey = $secrets['deploy_public_key'] ?? null;
    $adminKeys = run("cat /root/.ssh/authorized_keys 2>/dev/null || echo ''");

    if ($deployKey) {
        info('Adding deploy key with command restriction (CI/CD only)...');
        $wrapperPath = get('deploy_wrapper_path');
        installDeployerShell($wrapperPath);
        $restrictedKey = "command=\"{$wrapperPath}\",no-port-forwarding,no-X11-forwarding,no-agent-forwarding {$deployKey}";
        run("echo " . escapeshellarg($restrictedKey) . " >> {$authorizedKeysFile}");
        info('Deploy key added - restricted to deployer commands only');
    }

    if (! empty(trim($adminKeys))) {
        info('Adding admin keys with full shell access...');
        run("echo " . escapeshellarg($adminKeys) . " >> {$authorizedKeysFile}");
        info('Admin keys added from root');
    }

    if (! $deployKey && empty(trim($adminKeys))) {
        warning('No SSH keys configured! Add deploy_public_key or ensure root has authorized_keys.');
    }

    run("chown -R {$user}:{$user} {$sshDir}");
    run("chmod 700 {$sshDir}");
    run("chmod 600 {$authorizedKeysFile} 2>/dev/null || true");

    // Generate SSH key for GitHub access
    info('Generating SSH key for GitHub access...');
    run("sudo -u {$user} ssh-keygen -t ed25519 -N '' -f /home/{$user}/.ssh/id_ed25519 -C '{$user}@{$hostname}' 2>/dev/null || true");

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

    // Write sudoers file
    $escapedContent = escapeshellarg($sudoersContent);
    run("echo {$escapedContent} > /etc/sudoers.d/{$user}");
    run("chmod 440 /etc/sudoers.d/{$user}");

    // Validate sudoers file
    run("visudo -c -f /etc/sudoers.d/{$user}");

    $deployPath = get('deploy_path', '~/app');
    $expandedPath = str_replace('~', "/home/{$user}", $deployPath);
    run("mkdir -p {$expandedPath}");
    run("chown -R {$user}:{$user} {$expandedPath}");

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
    $rules = run("cat /etc/sudoers.d/{$user} 2>/dev/null || echo 'No sudoers file found'");
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
    run("echo '{$user} ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/{$user}");
    run("chmod 440 /etc/sudoers.d/{$user}");
    warning("Unrestricted sudo enabled for {$user}. Re-run provision:bootstrap to restore restrictions.");
});
