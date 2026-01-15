<?php

namespace Deployer;

desc('Set up SQLite database directory with proper permissions');
task('provision:sqlite', function () {
    $deployPath = get('deploy_path');
    $homePath = run('echo $HOME');
    $fullPath = str_replace('~', $homePath, $deployPath);
    $databasePath = "{$fullPath}/shared/database";

    info('Setting up SQLite database directory...');

    // Create shared/database directory
    sudo("mkdir -p {$databasePath}");

    // Set ownership to www-data:www-data (PHP-FPM needs write access)
    sudo("chown -R www-data:www-data {$databasePath}");

    // Set directory permissions to 755
    sudo("chmod 755 {$databasePath}");

    // Set database file permissions to 664 if files exist
    sudo("chmod 664 {$databasePath}/*.sqlite 2>/dev/null || true");

    info("SQLite directory created: {$databasePath}");
    info('Ownership set to www-data:www-data for PHP-FPM access');
});

desc('Fix SQLite permissions on existing installation');
task('sqlite:fix-permissions', function () {
    $deployPath = get('deploy_path');
    $homePath = run('echo $HOME');
    $fullPath = str_replace('~', $homePath, $deployPath);
    $databasePath = "{$fullPath}/shared/database";

    info('Fixing SQLite permissions...');

    // Check if directory exists
    $exists = run("test -d {$databasePath} && echo 'yes' || echo 'no'");

    if (trim($exists) !== 'yes') {
        warning("Database directory not found: {$databasePath}");
        info('Run provision:sqlite to create it.');
        return;
    }

    // Fix ownership
    sudo("chown -R www-data:www-data {$databasePath}");
    sudo("chmod 755 {$databasePath}");

    // Fix file permissions
    sudo("chmod 664 {$databasePath}/*.sqlite 2>/dev/null || true");

    info('SQLite permissions fixed');

    // Show current state
    $listing = run("ls -la {$databasePath}");
    writeln($listing);
});

desc('Show SQLite database status');
task('sqlite:status', function () {
    $deployPath = get('deploy_path');
    $homePath = run('echo $HOME');
    $fullPath = str_replace('~', $homePath, $deployPath);
    $databasePath = "{$fullPath}/shared/database";

    info("SQLite database directory: {$databasePath}");

    $exists = run("test -d {$databasePath} && echo 'yes' || echo 'no'");

    if (trim($exists) !== 'yes') {
        warning('Database directory does not exist');
        return;
    }

    $listing = run("ls -la {$databasePath}");
    writeln($listing);

    // Check ownership
    $owner = run("stat -c '%U:%G' {$databasePath}");
    info("Directory ownership: {$owner}");

    // Check for database files
    $files = run("find {$databasePath} -name '*.sqlite' -type f 2>/dev/null | wc -l");
    info("SQLite files found: " . trim($files));
});

desc('Create empty SQLite database file');
task('sqlite:create', function () {
    $deployPath = get('deploy_path');
    $homePath = run('echo $HOME');
    $fullPath = str_replace('~', $homePath, $deployPath);
    $databasePath = "{$fullPath}/shared/database";
    $dbFile = "{$databasePath}/database.sqlite";

    $exists = run("test -f {$dbFile} && echo 'yes' || echo 'no'");

    if (trim($exists) === 'yes') {
        info('SQLite database already exists');
        return;
    }

    // Create database directory if needed
    sudo("mkdir -p {$databasePath}");

    // Create empty database file
    sudo("touch {$dbFile}");

    // Set proper ownership and permissions
    sudo("chown www-data:www-data {$dbFile}");
    sudo("chmod 664 {$dbFile}");

    info("Created: {$dbFile}");
});

desc('Backup SQLite database');
task('sqlite:backup', function () {
    $deployPath = get('deploy_path');
    $homePath = run('echo $HOME');
    $fullPath = str_replace('~', $homePath, $deployPath);
    $databasePath = "{$fullPath}/shared/database";
    $dbFile = "{$databasePath}/database.sqlite";
    $backupPath = "{$fullPath}/shared/backups";
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = "{$backupPath}/database_{$timestamp}.sqlite";

    $exists = run("test -f {$dbFile} && echo 'yes' || echo 'no'");

    if (trim($exists) !== 'yes') {
        warning('No SQLite database found to backup');
        return;
    }

    // Create backup directory
    run("mkdir -p {$backupPath}");

    // Copy database file
    run("cp {$dbFile} {$backupFile}");

    info("Backup created: {$backupFile}");
});
