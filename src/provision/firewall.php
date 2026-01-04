<?php

namespace Deployer;

desc('Configure UFW firewall with secure defaults');
task('provision:firewall', function () {
    info('Configuring UFW firewall...');

    sudo('apt-get update');
    sudo('apt-get install -y ufw');
    sudo('ufw --force reset');
    sudo('ufw default deny incoming');
    sudo('ufw default allow outgoing');
    sudo('ufw allow ssh');
    sudo('ufw limit ssh/tcp');
    sudo('ufw allow http');
    sudo('ufw allow https');
    sudo('ufw --force enable');

    $status = sudo('ufw status verbose');
    writeln($status);

    info('Firewall configured: SSH (rate-limited), HTTP, HTTPS');
});

desc('Show firewall status and rules');
task('provision:firewall:status', function () {
    $status = sudo('ufw status numbered');
    writeln($status);
});

desc('Disable firewall (use with caution)');
task('provision:firewall:disable', function () {
    warning('Disabling firewall - only do this for debugging!');

    if (! askConfirmation('Are you sure you want to disable the firewall?', false)) {
        info('Aborted.');

        return;
    }

    sudo('ufw disable');
    warning('Firewall disabled. Re-enable with: dep provision:firewall');
});
