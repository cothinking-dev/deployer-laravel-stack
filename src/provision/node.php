<?php

namespace Deployer;

desc('Install Node.js via NodeSource');
task('provision:node', function () {
    $version = get('node_version', '22');

    info("Installing Node.js {$version}.x...");

    run("curl -fsSL https://deb.nodesource.com/setup_{$version}.x -o /tmp/nodesource_setup.sh");
    sudo('bash /tmp/nodesource_setup.sh');
    sudo('apt-get install -y nodejs');
    run('rm -f /tmp/nodesource_setup.sh');

    $nodeVersion = run('node --version');
    $npmVersion = run('npm --version');
    info("Node.js {$nodeVersion}, npm {$npmVersion}");
});

desc('Show Node.js and npm versions');
task('node:info', function () {
    writeln('Node.js: ' . run('node --version'));
    writeln('npm: ' . run('npm --version'));
});
