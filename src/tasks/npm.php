<?php

namespace Deployer;

desc('Install npm dependencies');
task('npm:install', function () {
    run('cd {{release_path}} && npm ci --no-audit --no-fund');
});

desc('Build frontend assets for production');
task('npm:build', function () {
    run('cd {{release_path}} && npm run build');
});

desc('Build frontend assets for development');
task('npm:dev', function () {
    run('cd {{release_path}} && npm run dev');
});

desc('Show npm package versions');
task('npm:outdated', function () {
    $result = run('cd {{release_path}} && npm outdated 2>&1 || true');
    writeln($result);
});
