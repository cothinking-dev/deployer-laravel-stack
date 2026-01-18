<?php

namespace Deployer;

desc('Interactive wizard to migrate existing data (media files, SQLite database) to the server');
task('data:migrate', function () {
    writeln('');
    writeln('<fg=cyan>╔════════════════════════════════════════════════════════════╗</>');
    writeln('<fg=cyan>║           Data Migration Wizard                            ║</>');
    writeln('<fg=cyan>╚════════════════════════════════════════════════════════════╝</>');
    writeln('');
    writeln('This wizard uploads existing data from your local machine to the server.');
    writeln('');
    writeln('<fg=yellow>Prerequisites:</>');
    writeln('  • Server is provisioned (setup:environment completed)');
    writeln('  • At least one deployment has been run');
    writeln('');

    $deployPath = get('deploy_path');
    if (empty($deployPath)) {
        throw new \RuntimeException('deploy_path is not configured');
    }

    $hasSqlite = get('db_connection', 'pgsql') === 'sqlite';
    $storageLinks = get('storage_links', []);
    $migratedAnything = false;

    // ─────────────────────────────────────────────────────────────────────────
    // SQLite Database Migration
    // ─────────────────────────────────────────────────────────────────────────

    if ($hasSqlite) {
        writeln('<fg=yellow>SQLite Database</>');
        writeln('');

        $localDb = findLocalSqliteDatabase();

        if (!$localDb) {
            writeln('  No local SQLite database found in common locations.');
            $localDb = ask('  Enter path (or leave empty to skip)', '');
        }

        if ($localDb) {
            $localDb = validateLocalPath($localDb, 'file');

            if ($localDb) {
                $size = formatBytes(filesize($localDb));
                writeln("  Found: <fg=green>{$localDb}</> ({$size})");

                if (askConfirmation('  Upload to server?', true)) {
                    $remotePath = "{$deployPath}/shared/database/database.sqlite";

                    if (test("[ -f {$remotePath} ]")) {
                        writeln('  <fg=yellow>⚠ Remote database exists.</>');

                        // Create backup before overwrite
                        if (askConfirmation('  Create backup and overwrite?', false)) {
                            $backupPath = "{$deployPath}/shared/database/database_" . date('Y-m-d_H-i-s') . '.sqlite.bak';
                            run("cp {$remotePath} {$backupPath}");
                            info("  Backup created: {$backupPath}");

                            uploadFile($localDb, $remotePath);
                            $migratedAnything = true;
                        } else {
                            writeln('  Skipped');
                        }
                    } else {
                        uploadFile($localDb, $remotePath);
                        $migratedAnything = true;
                    }
                }
            }
        }

        writeln('');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Media/Storage Directory Migration (from storage_links config)
    // ─────────────────────────────────────────────────────────────────────────

    if (!empty($storageLinks)) {
        writeln('<fg=yellow>Media Directories</> (from storage_links config)');
        writeln('');

        foreach ($storageLinks as $publicPath => $sharedPath) {
            // Validate paths don't contain traversal
            if (!isPathSafe($publicPath) || !isPathSafe($sharedPath)) {
                warning("  Skipping unsafe path: {$publicPath} → {$sharedPath}");
                continue;
            }

            $localPath = "public/{$publicPath}";

            if (!is_dir($localPath)) {
                continue;
            }

            // Resolve to real path and verify it's within project
            $realLocalPath = realpath($localPath);
            $projectRoot = realpath(getcwd());

            if (!$realLocalPath || !str_starts_with($realLocalPath, $projectRoot)) {
                warning("  Skipping path outside project: {$localPath}");
                continue;
            }

            $stats = getDirectoryStats($realLocalPath);
            if ($stats['count'] === 0) {
                continue;
            }

            writeln("  <fg=green>{$localPath}</> → shared/{$sharedPath}");
            writeln("    {$stats['count']} files, " . formatBytes($stats['size']));

            if (askConfirmation('    Upload?', true)) {
                $remotePath = "{$deployPath}/shared/{$sharedPath}";
                uploadDirectory($realLocalPath, $remotePath);
                $migratedAnything = true;
            }

            writeln('');
        }
    } else {
        writeln('<fg=yellow>Media Directories</>');
        writeln('  No storage_links configured in deploy.php');
        writeln('');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Summary
    // ─────────────────────────────────────────────────────────────────────────

    writeln('<fg=cyan>════════════════════════════════════════════════════════════</>');

    if ($migratedAnything) {
        writeln('<fg=green>Data migration complete!</>');
        writeln('');
        writeln('Next steps:');
        writeln('  1. Deploy to create symlinks:');
        writeln('       ./deploy/dep deploy ' . getStage());

        $webServer = get('web_server', 'fpm');
        if ($webServer === 'octane') {
            writeln('');
            writeln('  2. Regenerate Octane service (for new ReadWritePaths):');
            writeln('       ./deploy/dep octane:service ' . getStage());
            writeln('       ./deploy/dep octane:restart ' . getStage());
        }
    } else {
        writeln('<fg=yellow>No data was migrated.</>');
    }

    writeln('');
});

// ─────────────────────────────────────────────────────────────────────────────
// Helper Functions
// ─────────────────────────────────────────────────────────────────────────────

function findLocalSqliteDatabase(): ?string
{
    $paths = [
        'database/database.sqlite',
        'database/db.sqlite',
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Validate a user-provided local path is safe and exists.
 * Returns the validated real path, or null if invalid.
 */
function validateLocalPath(string $path, string $type = 'file'): ?string
{
    // Reject empty or obviously malicious paths
    if (empty($path) || !isPathSafe($path)) {
        warning("  Invalid path: {$path}");
        return null;
    }

    // Check existence
    if ($type === 'file' && !is_file($path)) {
        warning("  File not found: {$path}");
        return null;
    }

    if ($type === 'dir' && !is_dir($path)) {
        warning("  Directory not found: {$path}");
        return null;
    }

    // Resolve to real path (follows symlinks, resolves ..)
    $realPath = realpath($path);
    if (!$realPath) {
        warning("  Cannot resolve path: {$path}");
        return null;
    }

    // Ensure path is within current working directory (project root)
    $projectRoot = realpath(getcwd());
    if (!str_starts_with($realPath, $projectRoot)) {
        warning("  Path outside project directory: {$path}");
        return null;
    }

    return $realPath;
}

/**
 * Check if a path component is safe (no traversal or absolute paths).
 */
function isPathSafe(string $path): bool
{
    // Reject absolute paths
    if (str_starts_with($path, '/')) {
        return false;
    }

    // Reject path traversal
    if (str_contains($path, '..')) {
        return false;
    }

    // Reject null bytes (injection attempt)
    if (str_contains($path, "\0")) {
        return false;
    }

    return true;
}

function uploadFile(string $localPath, string $remotePath): void
{
    info('Uploading ' . formatBytes(filesize($localPath)) . '...');

    run('mkdir -p ' . escapeshellarg(dirname($remotePath)));
    upload($localPath, $remotePath);

    $httpUser = get('http_user', 'www-data');
    sudo('chown ' . escapeshellarg("{$httpUser}:{$httpUser}") . ' ' . escapeshellarg($remotePath));
    sudo('chmod 664 ' . escapeshellarg($remotePath));

    info("Uploaded to {$remotePath}");
}

function uploadDirectory(string $localPath, string $remotePath): void
{
    $stats = getDirectoryStats($localPath);
    info("Uploading {$stats['count']} files (" . formatBytes($stats['size']) . ')...');

    run('mkdir -p ' . escapeshellarg($remotePath));
    upload($localPath . '/', $remotePath . '/');

    $httpUser = get('http_user', 'www-data');
    sudo('chown -R ' . escapeshellarg("{$httpUser}:{$httpUser}") . ' ' . escapeshellarg($remotePath));
    sudo('chmod -R 775 ' . escapeshellarg($remotePath));

    info("Uploaded to {$remotePath}");
}

function getDirectoryStats(string $path): array
{
    $size = 0;
    $count = 0;

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(
            $path,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
        ),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    // Set max depth to prevent infinite loops from circular symlinks
    $iterator->setMaxDepth(20);

    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $size += $file->getSize();
            $count++;
        }
    }

    return ['size' => $size, 'count' => $count];
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $power = min($power, count($units) - 1);

    return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
}

function getStage(): string
{
    return get('labels')['stage'] ?? currentHost()->getAlias();
}
