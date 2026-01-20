<?php

namespace Deployer;

// Cache configuration
set('npm_cache_enabled', true);
set('npm_cache_dir', '{{deploy_path}}/shared/.npm-cache');
set('npm_lockfile_hash_file', '{{deploy_path}}/shared/.npm-lockfile-hash');

// Skip build configuration
set('npm_skip_build_enabled', true);
set('npm_assets_hash_file', '{{deploy_path}}/shared/.assets-hash');
set('npm_build_output_dir', 'public/build'); // Where Vite outputs

/**
 * Calculate hash of package-lock.json.
 */
function getNpmLockfileHash(): string
{
    return run('cd {{release_path}} && sha256sum package-lock.json 2>/dev/null | cut -d" " -f1 || echo ""');
}

/**
 * Calculate hash of source files that affect build output.
 * Includes package-lock.json, vite.config.js, resources/, and tailwind config.
 *
 * IMPORTANT: Blade files are included because Tailwind scans them for classes.
 * Any new Tailwind class in a Blade file will change the CSS output.
 */
function getAssetsSourceHash(): string
{
    // Hash key source files that affect build output
    // Note: *.blade.php included because Tailwind purges unused classes based on template content
    $script = <<<'BASH'
cd {{release_path}}
{
    sha256sum package-lock.json 2>/dev/null
    sha256sum vite.config.* 2>/dev/null
    sha256sum tailwind.config.* 2>/dev/null
    sha256sum postcss.config.* 2>/dev/null
    find resources -type f \( -name "*.js" -o -name "*.ts" -o -name "*.vue" -o -name "*.css" -o -name "*.scss" -o -name "*.blade.php" \) -exec sha256sum {} \; 2>/dev/null
} | sort | sha256sum | cut -d" " -f1
BASH;

    return trim(run($script));
}

desc('Install npm dependencies (with caching)');
task('npm:install', function () {
    $cacheEnabled = get('npm_cache_enabled', true);

    if (! $cacheEnabled) {
        run('cd {{release_path}} && npm ci --no-audit --no-fund');

        return;
    }

    $cacheDir = get('npm_cache_dir');
    $hashFile = get('npm_lockfile_hash_file');

    // Ensure cache directory exists
    run("mkdir -p {$cacheDir}");

    // Get current lockfile hash
    $currentHash = getNpmLockfileHash();

    if (empty($currentHash)) {
        warning('No package-lock.json found, running fresh npm ci');
        run('cd {{release_path}} && npm ci --no-audit --no-fund');

        return;
    }

    // Check if we have a cached node_modules for this hash
    $cachedModules = "{$cacheDir}/node_modules-{$currentHash}.tar.gz";
    $previousHash = run("cat {$hashFile} 2>/dev/null || echo ''");

    if (trim($previousHash) === $currentHash && test("[ -f {$cachedModules} ]")) {
        info('package-lock.json unchanged, restoring cached node_modules');
        run("cd {{release_path}} && tar -xzf {$cachedModules}");
        // Quick npm ci to verify and update if needed (much faster with existing modules)
        run('cd {{release_path}} && npm ci --no-audit --no-fund --prefer-offline');
    } else {
        info('package-lock.json changed, running fresh npm ci');
        run('cd {{release_path}} && npm ci --no-audit --no-fund');

        // Cache the fresh node_modules
        info('Caching node_modules for future deploys...');
        run("cd {{release_path}} && tar -czf {$cachedModules} node_modules");

        // Clean up old cache files (keep last 3)
        run("cd {$cacheDir} && ls -t node_modules-*.tar.gz 2>/dev/null | tail -n +4 | xargs -r rm");

        // Store current hash
        run("echo '{$currentHash}' > {$hashFile}");
    }
});

desc('Build frontend assets for production (with skip-build optimization)');
task('npm:build', function () {
    $skipEnabled = get('npm_skip_build_enabled', true);
    $assetsHashFile = get('npm_assets_hash_file');
    $buildOutputDir = get('npm_build_output_dir', 'public/build');

    if (! $skipEnabled) {
        run('cd {{release_path}} && npm run build');

        return;
    }

    $currentHash = getAssetsSourceHash();
    $previousHash = run("cat {$assetsHashFile} 2>/dev/null || echo ''");

    // Check if we can copy from previous release
    $previousRelease = run('ls -1d {{deploy_path}}/releases/* 2>/dev/null | sort -V | tail -2 | head -1 || echo ""');
    $previousBuildPath = trim($previousRelease) . '/' . $buildOutputDir;

    if (trim($previousHash) === $currentHash && test("[ -d {$previousBuildPath} ]")) {
        info('Assets unchanged, copying build from previous release');

        // Ensure parent directory exists
        run("mkdir -p {{release_path}}/{$buildOutputDir}");

        // Copy build artifacts from previous release
        run("cp -r {$previousBuildPath}/* {{release_path}}/{$buildOutputDir}/");
    } else {
        info('Assets changed, running npm build');
        run('cd {{release_path}} && npm run build');

        // Store current hash for next deploy
        run("echo '{$currentHash}' > {$assetsHashFile}");
    }
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

desc('Clear npm cache');
task('npm:cache:clear', function () {
    $cacheDir = get('npm_cache_dir');
    $hashFile = get('npm_lockfile_hash_file');
    $assetsHashFile = get('npm_assets_hash_file');

    run("rm -rf {$cacheDir}/*");
    run("rm -f {$hashFile}");
    run("rm -f {$assetsHashFile}");

    info('npm cache cleared');
});

desc('Show npm cache status');
task('npm:cache:status', function () {
    $cacheDir = get('npm_cache_dir');
    $hashFile = get('npm_lockfile_hash_file');
    $assetsHashFile = get('npm_assets_hash_file');

    writeln('npm Cache Status:');
    writeln('');

    // Current lockfile hash
    $currentLockHash = getNpmLockfileHash();
    $storedLockHash = run("cat {$hashFile} 2>/dev/null || echo 'not set'");
    writeln("  Lockfile hash (current):  {$currentLockHash}");
    writeln("  Lockfile hash (stored):   {$storedLockHash}");
    writeln("  Lockfile match: " . ($currentLockHash === trim($storedLockHash) ? 'YES' : 'NO'));
    writeln('');

    // Current assets hash
    $currentAssetsHash = getAssetsSourceHash();
    $storedAssetsHash = run("cat {$assetsHashFile} 2>/dev/null || echo 'not set'");
    writeln("  Assets hash (current):    {$currentAssetsHash}");
    writeln("  Assets hash (stored):     {$storedAssetsHash}");
    writeln("  Assets match: " . ($currentAssetsHash === trim($storedAssetsHash) ? 'YES' : 'NO'));
    writeln('');

    // Cached archives
    $cacheFiles = run("ls -lh {$cacheDir}/node_modules-*.tar.gz 2>/dev/null || echo 'No cached archives'");
    writeln('  Cached archives:');
    writeln("  {$cacheFiles}");
});
