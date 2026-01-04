<?php

namespace Deployer;

desc('Bootstrap server: create deployer user with SSH keys (run as root)');
task('provision:bootstrap', function () {
    $secrets = has('secrets') ? get('secrets') : [];
    $sudoPass = $secrets['sudo_pass'] ?? get('sudo_pass') ?? null;

    if ($sudoPass === null) {
        throw new \RuntimeException('No sudo password configured for deployer user.');
    }

    $user = get('remote_user', 'deployer');

    info("Creating user '{$user}' and setting up SSH access...");

    run("id {$user} &>/dev/null || adduser --disabled-password --gecos '' {$user}");
    run("echo '{$user}:%secret%' | chpasswd", secret: $sudoPass);
    run("usermod -aG sudo {$user}");

    run("mkdir -p /home/{$user}/.ssh");
    run("cp /root/.ssh/authorized_keys /home/{$user}/.ssh/authorized_keys 2>/dev/null || true");
    run("chown -R {$user}:{$user} /home/{$user}/.ssh");
    run("chmod 700 /home/{$user}/.ssh");
    run("chmod 600 /home/{$user}/.ssh/authorized_keys 2>/dev/null || true");

    $deployPath = get('deploy_path', '~/app');
    $expandedPath = str_replace('~', "/home/{$user}", $deployPath);
    run("mkdir -p {$expandedPath}");
    run("chown -R {$user}:{$user} {$expandedPath}");

    info("User '{$user}' created with SSH access.");
    info("Deploy path '{$deployPath}' created.");
})->oncePerNode();
