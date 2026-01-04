<?php

namespace Deployer;

set('bootstrap_user', 'deployer');

desc('Bootstrap server: create deployer user with SSH keys and passwordless sudo (run as root)');
task('provision:bootstrap', function () {
    $secrets = has('secrets') ? get('secrets') : [];
    $sudoPass = $secrets['sudo_pass'] ?? get('sudo_pass') ?? null;

    if ($sudoPass === null) {
        throw new \RuntimeException('No sudo password configured for deployer user.');
    }

    $user = get('bootstrap_user');

    info("Creating user '{$user}'...");

    run("id {$user} &>/dev/null || adduser --disabled-password --gecos '' {$user}");
    run("echo '{$user}:%secret%' | chpasswd", secret: $sudoPass);
    run("usermod -aG sudo {$user}");

    // Make home directory traversable by web servers (Caddy, PHP-FPM)
    run("chmod 755 /home/{$user}");

    info("Setting up SSH access...");

    run("mkdir -p /home/{$user}/.ssh");
    run("cp /root/.ssh/authorized_keys /home/{$user}/.ssh/authorized_keys 2>/dev/null || true");
    run("chown -R {$user}:{$user} /home/{$user}/.ssh");
    run("chmod 700 /home/{$user}/.ssh");
    run("chmod 600 /home/{$user}/.ssh/authorized_keys 2>/dev/null || true");

    info("Configuring passwordless sudo...");

    run("echo '{$user} ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/{$user}");
    run("chmod 440 /etc/sudoers.d/{$user}");

    $deployPath = get('deploy_path', '~/app');
    $expandedPath = str_replace('~', "/home/{$user}", $deployPath);
    run("mkdir -p {$expandedPath}");
    run("chown -R {$user}:{$user} {$expandedPath}");

    info("User '{$user}' created with SSH access and passwordless sudo.");
    info("Deploy path '{$deployPath}' created.");
    info("Now run: ./deploy/dep provision:all prod");
})->oncePerNode();
