<?php

namespace Deployer;

/**
 * Get a safe key name for the project (used in file names).
 */
function getProjectKeyName(): string
{
    $repo = get('repository');

    if (preg_match('#git@github\.com:(.+?)\.git#', $repo, $matches)) {
        // Convert org/repo to org_repo
        return str_replace('/', '_', $matches[1]);
    }

    // Fallback to application name
    $app = get('application', 'default');

    return preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($app));
}

/**
 * Get the path to the project's deploy key.
 */
function getProjectKeyPath(bool $public = false): string
{
    $keyName = getProjectKeyName();
    $ext = $public ? '.pub' : '';

    $remoteUser = get('remote_user', 'deployer');
    $home = $remoteUser === 'root' ? '/home/deployer' : run('echo $HOME');

    return "{$home}/.ssh/deploy_{$keyName}{$ext}";
}

desc('Generate project-specific deploy key (for multi-project servers)');
task('github:generate-key', function () {
    $repo = get('repository');

    if (! preg_match('#git@github\.com:(.+?)\.git#', $repo, $matches)) {
        warning('Could not parse GitHub repository from: ' . $repo);

        return;
    }

    $repoPath = $matches[1];
    $keyPath = getProjectKeyPath();
    $hostname = run('hostname');
    $keyComment = "deploy-{$repoPath}@{$hostname}";

    // Check if key already exists
    if (test("[ -f {$keyPath} ]")) {
        info("Deploy key already exists: {$keyPath}");
        $pubKey = run("cat {$keyPath}.pub");
        info("Public key:\n{$pubKey}");

        return;
    }

    info("Generating deploy key for {$repoPath}...");

    // Generate the key
    run("ssh-keygen -t ed25519 -N '' -f {$keyPath} -C '{$keyComment}'");

    // Set correct permissions
    run("chmod 600 {$keyPath}");
    run("chmod 644 {$keyPath}.pub");

    $pubKey = run("cat {$keyPath}.pub");
    info("Deploy key generated:\n{$pubKey}");

    // Configure SSH to use this key for this repo
    configureSshForRepo($repoPath, $keyPath);

    info("Add this key to: https://github.com/{$repoPath}/settings/keys");
    info("Or run: ./deploy/dep github:deploy-key server");
})->once();

/**
 * Configure SSH to use a specific key for a GitHub repo.
 * Handles deduplication to prevent multiple identical entries.
 */
function configureSshForRepo(string $repoPath, string $keyPath): void
{
    $remoteUser = get('remote_user', 'deployer');
    $home = $remoteUser === 'root' ? '/home/deployer' : run('echo $HOME');
    $sshConfigPath = "{$home}/.ssh/config";

    // Create a unique host alias for this repo
    $hostAlias = 'github-' . str_replace('/', '-', $repoPath);

    // Ensure .ssh directory exists with correct permissions
    run("mkdir -p {$home}/.ssh && chmod 700 {$home}/.ssh");

    // Check if already configured
    $existingConfig = run("cat {$sshConfigPath} 2>/dev/null || echo ''");

    if (str_contains($existingConfig, "Host {$hostAlias}")) {
        // Check if the existing config points to the same key
        $existingKeyMatch = preg_match(
            "/Host {$hostAlias}[^H]*IdentityFile ([^\n]+)/",
            $existingConfig,
            $matches
        );

        if ($existingKeyMatch && trim($matches[1]) === $keyPath) {
            info("SSH config already exists and is correct for {$hostAlias}");

            return;
        }

        // Key path is different, need to update the config
        info("Updating SSH config for {$hostAlias} (key path changed)");

        // Remove the old config block
        $newConfig = removeSshHostBlock($existingConfig, $hostAlias);
        run("echo " . escapeshellarg($newConfig) . " > {$sshConfigPath}");
    }

    $sshConfig = <<<SSH

# Deploy key for {$repoPath}
Host {$hostAlias}
    HostName github.com
    User git
    IdentityFile {$keyPath}
    IdentitiesOnly yes

SSH;

    run("echo " . escapeshellarg($sshConfig) . " >> {$sshConfigPath}");
    run("chmod 600 {$sshConfigPath}");

    info("SSH config added for {$hostAlias}");

    // Validate the SSH config
    $validateResult = run("ssh -G {$hostAlias} 2>&1 | grep -q 'hostname github.com' && echo 'VALID' || echo 'INVALID'");

    if (trim($validateResult) !== 'VALID') {
        warning("SSH config validation failed for {$hostAlias}. Check {$sshConfigPath}");
    }

    // Update the repository URL to use the host alias
    info("NOTE: Update your repository URL to use: git@{$hostAlias}:{$repoPath}.git");
}

/**
 * Remove an SSH host block from the config.
 */
function removeSshHostBlock(string $config, string $hostAlias): string
{
    // Pattern to match the entire Host block
    // Matches from "Host {alias}" to the next "Host " or end of file
    $pattern = "/\n?# Deploy key for [^\n]*\nHost {$hostAlias}\n(?:(?!Host ).)*\n?/s";

    return preg_replace($pattern, "\n", $config);
}

desc('Add server deploy key to GitHub via gh CLI');
task('github:deploy-key', function () {
    $repo = get('repository');

    if (! preg_match('#git@github\.com:(.+?)\.git#', $repo, $matches)) {
        warning('Could not parse GitHub repository from: ' . $repo);

        return;
    }

    $repoPath = $matches[1];
    $hostname = run('hostname');

    // Check for project-specific key first, fall back to default
    $projectKeyPath = getProjectKeyPath(public: true);
    $defaultKeyPath = get('remote_user', 'deployer') === 'root'
        ? '/home/deployer/.ssh/id_ed25519.pub'
        : '~/.ssh/id_ed25519.pub';

    if (test("[ -f {$projectKeyPath} ]")) {
        $keyPath = $projectKeyPath;
        $keyTitle = "deploy-" . str_replace('/', '-', $repoPath) . "@{$hostname}";
        info("Using project-specific key: {$keyPath}");
    } else {
        $keyPath = $defaultKeyPath;
        $keyTitle = "deployer@{$hostname}";
        info("Using default deploy key: {$keyPath}");
        warning("For multi-project setups, run 'github:generate-key' first to create project-specific keys.");
    }

    $pubKey = run("cat {$keyPath} 2>/dev/null || echo ''");

    if (empty(trim($pubKey))) {
        warning("No deploy key found at {$keyPath}");
        warning("Run 'github:generate-key server' to create a project-specific key.");

        return;
    }

    info("Adding deploy key to {$repoPath}...");

    $existingKeys = runLocally("gh repo deploy-key list -R {$repoPath} 2>/dev/null || echo ''");

    if (str_contains($existingKeys, $keyTitle)) {
        info("Deploy key '{$keyTitle}' already exists, checking if it matches...");

        // Get the key ID for deletion
        $keyId = null;
        foreach (explode("\n", $existingKeys) as $line) {
            if (str_contains($line, $keyTitle)) {
                $parts = preg_split('/\s+/', trim($line));
                $keyId = $parts[0] ?? null;
                break;
            }
        }

        if ($keyId) {
            info("Replacing old deploy key (ID: {$keyId}) with new one...");
            $deleteResult = runLocally("echo 'y' | gh repo deploy-key delete {$keyId} -R {$repoPath} 2>&1 || echo 'DELETE_FAILED'");

            if (str_contains($deleteResult, 'DELETE_FAILED')) {
                warning("Failed to delete old deploy key: {$deleteResult}");
                warning('You may need to delete it manually at: https://github.com/' . $repoPath . '/settings/keys');

                return;
            }

            info('Old deploy key deleted successfully');
        }
    }

    $escapedKey = escapeshellarg(trim($pubKey));
    $result = runLocally("echo {$escapedKey} | gh repo deploy-key add - --title '{$keyTitle}' -R {$repoPath} 2>&1 || echo 'FAILED'");

    if (str_contains($result, 'FAILED') || str_contains($result, 'error')) {
        warning("Failed to add deploy key: {$result}");
        warning('You may need to add it manually or check gh auth status');

        return;
    }

    info("Deploy key added to GitHub: {$keyTitle}");
})->once();

desc('Show deploy key for this project');
task('github:show-key', function () {
    $projectKeyPath = getProjectKeyPath(public: true);
    $defaultKeyPath = get('remote_user', 'deployer') === 'root'
        ? '/home/deployer/.ssh/id_ed25519.pub'
        : '~/.ssh/id_ed25519.pub';

    if (test("[ -f {$projectKeyPath} ]")) {
        info("Project-specific key ({$projectKeyPath}):");
        writeln(run("cat {$projectKeyPath}"));
    } elseif (test("[ -f {$defaultKeyPath} ]")) {
        info("Default key ({$defaultKeyPath}):");
        writeln(run("cat {$defaultKeyPath}"));
        warning("No project-specific key found. Run 'github:generate-key' for multi-project setups.");
    } else {
        warning("No deploy key found.");
    }
});
