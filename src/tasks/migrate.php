<?php

namespace Deployer;

// Migration configuration
set('migrate_enabled', true);
set('migrate_backup_enabled', true);
set('migrate_backup_path', '{{deploy_path}}/shared/backups');
set('migrate_backup_keep', 5); // Keep last 5 backups
set('migrate_timeout', 300); // 5 minutes for migrations
set('migrate_force', true); // Use --force in production

desc('Ensure SQLite database file exists');
task('db:ensure-sqlite', function () {
    $dbConnection = get('db_connection', 'pgsql');

    if ($dbConnection !== 'sqlite') {
        return;
    }

    // Get DB_DATABASE from shared_env or env_base
    $sharedEnv = has('shared_env') ? get('shared_env') : [];
    $dbPath = $sharedEnv['DB_DATABASE'] ?? 'database/database.sqlite';

    // Resolve relative path from release_path
    $fullPath = "{{release_path}}/{$dbPath}";

    // Check if file exists
    if (test("[ -f {$fullPath} ]")) {
        info("SQLite database already exists: {$dbPath}");

        return;
    }

    info("Creating SQLite database file: {$dbPath}");

    // Ensure parent directory exists (should already exist via shared_dirs)
    $parentDir = dirname($fullPath);
    run("mkdir -p {$parentDir}");

    // Create empty SQLite file
    run("touch {$fullPath}");
    run("chmod 664 {$fullPath}");

    info('SQLite database file created');
});

desc('Run migrations with automatic backup');
task('migrate:safe', function () {
    if (! get('migrate_enabled', true)) {
        info('Migrations disabled, skipping...');

        return;
    }

    $backupEnabled = get('migrate_backup_enabled', true);

    // Check if there are pending migrations
    $status = run('cd {{release_path}} && {{bin/php}} artisan migrate:status --pending 2>&1 || echo "NO_PENDING"');

    if (str_contains($status, 'NO_PENDING') || str_contains($status, 'Nothing to migrate') || str_contains($status, 'No pending migrations')) {
        info('No pending migrations');

        return;
    }

    info('Pending migrations detected:');
    writeln($status);
    writeln('');

    // Create backup before migrating
    if ($backupEnabled) {
        try {
            invoke('db:backup');
        } catch (\Throwable $e) {
            warning('Failed to create backup: ' . $e->getMessage());

            if (! askConfirmation('Continue without backup?', false)) {
                throw new \RuntimeException('Migration aborted: backup failed');
            }
        }
    }

    // Run migrations
    info('Running migrations...');
    $force = get('migrate_force', true) ? ' --force' : '';
    $timeout = get('migrate_timeout', 300);

    try {
        $output = run("cd {{release_path}} && {{bin/php}} artisan migrate{$force} 2>&1", timeout: $timeout);
        writeln($output);
        info('Migrations completed successfully');
    } catch (\Throwable $e) {
        warning('Migration failed: ' . $e->getMessage());

        if ($backupEnabled) {
            warning('A database backup was created before the failed migration.');
            warning('Run `dep db:restore` to restore from the backup if needed.');
        }

        throw $e;
    }
});

desc('Run migrations with --pretend (dry run)');
task('migrate:pretend', function () {
    info('Running migration dry-run...');

    $force = get('migrate_force', true) ? ' --force' : '';
    $output = run("cd {{release_path}} && {{bin/php}} artisan migrate{$force} --pretend 2>&1");

    if (empty(trim($output))) {
        info('No pending migrations');
    } else {
        writeln('');
        writeln('Migrations that would run:');
        writeln($output);
    }
});

desc('Show migration status');
task('migrate:status', function () {
    $output = run('cd {{deploy_path}}/current && {{bin/php}} artisan migrate:status 2>&1');
    writeln($output);
});

desc('Create database backup');
task('db:backup', function () {
    $dbConnection = get('db_connection', 'pgsql');
    $backupPath = get('migrate_backup_path', '{{deploy_path}}/shared/backups');
    $keepBackups = get('migrate_backup_keep', 5);
    $timestamp = date('Y-m-d-His');
    $stage = getStage();

    // Create backup directory
    run("mkdir -p {$backupPath}");

    if ($dbConnection === 'sqlite') {
        // SQLite backup: simple file copy
        $sharedEnv = has('shared_env') ? get('shared_env') : [];
        $dbPath = $sharedEnv['DB_DATABASE'] ?? 'database/database.sqlite';
        $sourcePath = "{{release_path}}/{$dbPath}";
        $backupFile = "{$backupPath}/database_{$stage}_{$timestamp}.sqlite";

        info("Creating SQLite backup: {$backupFile}");

        // Copy SQLite file
        $result = run("cp {$sourcePath} {$backupFile} 2>&1 && echo 'BACKUP_OK' || echo 'BACKUP_FAILED'");

        if (str_contains($result, 'BACKUP_FAILED')) {
            run("rm -f {$backupFile}");

            throw new \RuntimeException('SQLite backup failed');
        }

        // Verify backup file
        $fileSize = run("stat -f%z {$backupFile} 2>/dev/null || stat -c%s {$backupFile} 2>/dev/null || echo '0'");

        if ((int) trim($fileSize) < 100) {
            run("rm -f {$backupFile}");

            throw new \RuntimeException('Backup file is empty or too small');
        }

        $sizeKb = round((int) $fileSize / 1024, 2);
        info("Backup created: {$backupFile} ({$sizeKb} KB)");

        // Clean up old backups
        cleanupOldBackups($backupPath, 'database', $keepBackups, '.sqlite');
    } else {
        // PostgreSQL backup
        $dbName = get('db_name');
        $dbUser = get('db_username', 'deployer');
        $secrets = has('secrets') ? get('secrets') : [];
        $dbPass = $secrets['db_password'] ?? '';

        if (! $dbName || ! $dbPass) {
            throw new \RuntimeException('Database credentials not configured');
        }

        $backupFile = "{$backupPath}/{$dbName}_{$stage}_{$timestamp}.sql.gz";

        info("Creating PostgreSQL backup: {$backupFile}");

        // Run pg_dump with compression
        $result = run(
            "PGPASSWORD='%secret%' pg_dump -h 127.0.0.1 -U {$dbUser} {$dbName} | gzip > {$backupFile} 2>&1 && echo 'BACKUP_OK' || echo 'BACKUP_FAILED'",
            secret: $dbPass
        );

        if (str_contains($result, 'BACKUP_FAILED')) {
            run("rm -f {$backupFile}");

            throw new \RuntimeException('Database backup failed');
        }

        // Verify backup file was created and has content
        $fileSize = run("stat -f%z {$backupFile} 2>/dev/null || stat -c%s {$backupFile} 2>/dev/null || echo '0'");

        if ((int) trim($fileSize) < 100) {
            run("rm -f {$backupFile}");

            throw new \RuntimeException('Backup file is empty or too small');
        }

        $sizeKb = round((int) $fileSize / 1024, 2);
        info("Backup created: {$backupFile} ({$sizeKb} KB)");

        // Clean up old backups
        cleanupOldBackups($backupPath, $dbName, $keepBackups, '.sql.gz');
    }
});

/**
 * Remove old backups, keeping only the specified number.
 */
function cleanupOldBackups(string $backupPath, string $dbName, int $keep, string $extension = '.sql.gz'): void
{
    $pattern = match ($extension) {
        '.sqlite' => "{$backupPath}/{$dbName}_*.sqlite",
        '.sql.gz' => "{$backupPath}/{$dbName}_*.sql.gz",
        default => "{$backupPath}/{$dbName}_*{$extension}",
    };

    $backups = run("ls -1t {$pattern} 2>/dev/null || echo ''");
    $backupFiles = array_filter(explode("\n", trim($backups)));

    if (count($backupFiles) > $keep) {
        $toRemove = array_slice($backupFiles, $keep);

        foreach ($toRemove as $file) {
            run("rm -f {$file}");
        }

        info('Cleaned up ' . count($toRemove) . ' old backup(s)');
    }
}

desc('List available database backups');
task('db:backups', function () {
    $backupPath = get('migrate_backup_path', '{{deploy_path}}/shared/backups');
    $dbConnection = get('db_connection', 'pgsql');

    $pattern = $dbConnection === 'sqlite' ? '*.sqlite' : '*.sql.gz';
    $backups = run("ls -lh {$backupPath}/{$pattern} 2>/dev/null || echo 'No backups found'");
    writeln($backups);
});

desc('Restore database from backup');
task('db:restore', function () {
    $dbConnection = get('db_connection', 'pgsql');
    $backupPath = get('migrate_backup_path', '{{deploy_path}}/shared/backups');
    $stage = getStage();

    // List available backups based on database type
    if ($dbConnection === 'sqlite') {
        $pattern = "{$backupPath}/database_{$stage}_*.sqlite";
        $backups = run("ls -1t {$pattern} 2>/dev/null || echo ''");
    } else {
        $dbName = get('db_name');
        $pattern = "{$backupPath}/{$dbName}_{$stage}_*.sql.gz";
        $backups = run("ls -1t {$pattern} 2>/dev/null || echo ''");
    }

    $backupFiles = array_filter(explode("\n", trim($backups)));

    if (empty($backupFiles)) {
        warning('No backups found');

        return;
    }

    writeln('Available backups:');

    foreach ($backupFiles as $i => $file) {
        $size = run("ls -lh {$file} | awk '{print \$5}'");
        writeln("  [{$i}] " . basename($file) . " ({$size})");
    }

    $choice = ask('Enter backup number to restore:', '0');
    $selectedBackup = $backupFiles[(int) $choice] ?? null;

    if (! $selectedBackup) {
        warning('Invalid selection');

        return;
    }

    if (! askConfirmation("This will OVERWRITE the current database. Continue?", false)) {
        info('Restore cancelled');

        return;
    }

    // Put app in maintenance mode
    if (test('[ -f {{deploy_path}}/current/artisan ]')) {
        run('cd {{deploy_path}}/current && {{bin/php}} artisan down --retry=60');
    }

    info("Restoring from: " . basename($selectedBackup));

    // Restore based on database type
    if ($dbConnection === 'sqlite') {
        $sharedEnv = has('shared_env') ? get('shared_env') : [];
        $dbPath = $sharedEnv['DB_DATABASE'] ?? 'database/database.sqlite';
        $targetPath = "{{deploy_path}}/current/{$dbPath}";

        $result = run("cp {$selectedBackup} {$targetPath} 2>&1 && echo 'RESTORE_OK' || echo 'RESTORE_FAILED'");

        if (! str_contains($result, 'RESTORE_OK')) {
            warning('Restore may have encountered issues. Check the output above.');
        } else {
            info('SQLite database restored successfully');
        }
    } else {
        $dbName = get('db_name');
        $dbUser = get('db_username', 'deployer');
        $secrets = has('secrets') ? get('secrets') : [];
        $dbPass = $secrets['db_password'] ?? '';

        if (! $dbName || ! $dbPass) {
            throw new \RuntimeException('Database credentials not configured');
        }

        $result = run(
            "gunzip -c {$selectedBackup} | PGPASSWORD='%secret%' psql -h 127.0.0.1 -U {$dbUser} {$dbName} 2>&1 && echo 'RESTORE_OK'",
            secret: $dbPass
        );

        if (! str_contains($result, 'RESTORE_OK')) {
            warning('Restore may have encountered issues. Check the output above.');
        } else {
            info('PostgreSQL database restored successfully');
        }
    }

    // Bring app back up
    if (test('[ -f {{deploy_path}}/current/artisan ]')) {
        run('cd {{deploy_path}}/current && {{bin/php}} artisan up');
    }
});

desc('Rollback the last database migration');
task('migrate:rollback', function () {
    if (! askConfirmation('This will rollback the last migration batch. Continue?', false)) {
        info('Rollback cancelled');

        return;
    }

    $force = get('migrate_force', true) ? ' --force' : '';
    $output = run("cd {{deploy_path}}/current && {{bin/php}} artisan migrate:rollback{$force} 2>&1");

    writeln($output);
    info('Migration rollback completed');
});

desc('Reset and re-run all migrations (DANGEROUS)');
task('migrate:fresh', function () {
    warning('This will DROP ALL TABLES and re-run all migrations!');

    if (! askConfirmation('Are you absolutely sure?', false)) {
        info('Cancelled');

        return;
    }

    if (! askConfirmation('This cannot be undone. Type "yes" to confirm:', false)) {
        info('Cancelled');

        return;
    }

    // Force backup before fresh
    invoke('db:backup');

    $force = get('migrate_force', true) ? ' --force' : '';
    $output = run("cd {{deploy_path}}/current && {{bin/php}} artisan migrate:fresh{$force} 2>&1");

    writeln($output);
    warning('All tables dropped and migrations re-run');
});
