<?php

namespace Deployer;

// Default storage links for Laravel applications
// The 'storage' link is required for Laravel's public disk to work correctly with Deployer's shared storage
// Add project-specific links via: add('storage_links', ['custom-media' => 'custom-media']);
set('storage_links', [
    'storage' => 'storage/app/public',
]);

// HTTP user for web server (www-data on Debian/Ubuntu, nginx on RHEL/CentOS)
set('http_user', function () {
    // Try to detect from running processes
    $candidates = ['www-data', 'nginx', 'apache', 'http', '_www'];

    foreach ($candidates as $user) {
        if (test("id -u {$user} 2>/dev/null")) {
            return $user;
        }
    }

    return 'www-data'; // Default fallback
});

desc('Create custom storage symlinks from public to shared directories');
task('storage:link-custom', function () {
    $links = get('storage_links');

    if (empty($links)) {
        return;
    }

    $httpUser = get('http_user');

    foreach ($links as $publicPath => $sharedPath) {
        $sharedFullPath = "{{deploy_path}}/shared/{$sharedPath}";
        $publicFullPath = "{{release_path}}/public/{$publicPath}";

        // Create shared directory if it doesn't exist
        run("mkdir -p {$sharedFullPath}");

        // Set ownership to web server user so uploads work
        // Use sudo since deployer user may not own www-data group
        sudo("chown -R {$httpUser}:{$httpUser} {$sharedFullPath}");
        sudo("chmod -R 775 {$sharedFullPath}");

        // Remove any existing file/directory and create symlink
        run("rm -rf {$publicFullPath}");
        run("ln -s {$sharedFullPath} {$publicFullPath}");

        info("Linked public/{$publicPath} → shared/{$sharedPath} (owner: {$httpUser})");
    }
});

desc('Fix permissions on shared storage directories for web server uploads');
task('storage:fix-permissions', function () {
    $links = get('storage_links');
    $httpUser = get('http_user');

    if (empty($links)) {
        warning('No storage_links configured');

        return;
    }

    foreach ($links as $publicPath => $sharedPath) {
        $sharedFullPath = "{{deploy_path}}/shared/{$sharedPath}";

        if (test("[ -d {$sharedFullPath} ]")) {
            sudo("chown -R {$httpUser}:{$httpUser} {$sharedFullPath}");
            sudo("chmod -R 775 {$sharedFullPath}");
            info("Fixed permissions on shared/{$sharedPath} (owner: {$httpUser})");
        } else {
            warning("Directory shared/{$sharedPath} does not exist");
        }
    }
});
