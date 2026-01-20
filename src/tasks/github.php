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

// ─────────────────────────────────────────────────────────────────────────────
// GitHub Actions CI/CD Key Management
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Get the path to the CI/CD key (separate from deploy key).
 */
function getCiKeyPath(bool $public = false): string
{
    $remoteUser = get('remote_user', 'deployer');
    $home = $remoteUser === 'root' ? '/home/deployer' : run('echo $HOME');
    $ext = $public ? '.pub' : '';

    return "{$home}/.ssh/github_actions{$ext}";
}

desc('Generate dedicated SSH key for GitHub Actions CI/CD');
task('github:ci-key', function () {
    $keyPath = getCiKeyPath();
    $hostname = run('hostname');
    $keyComment = "github-actions@{$hostname}";

    // Check if key already exists
    if (test("[ -f {$keyPath} ]")) {
        info("CI key already exists: {$keyPath}");
        info("Use 'github:ci-key:show' to view the public key.");
        info("Use 'github:ci-key:revoke' to remove and regenerate.");

        return;
    }

    $remoteUser = get('remote_user', 'deployer');
    $home = $remoteUser === 'root' ? '/home/deployer' : run('echo $HOME');

    // Ensure .ssh directory exists
    run("mkdir -p {$home}/.ssh && chmod 700 {$home}/.ssh");

    info("Generating CI/CD SSH key for GitHub Actions...");

    // Generate Ed25519 key
    run("ssh-keygen -t ed25519 -N '' -f {$keyPath} -C '{$keyComment}'");

    // Set correct permissions
    run("chmod 600 {$keyPath}");
    run("chmod 644 {$keyPath}.pub");

    // Add public key to authorized_keys
    $pubKey = run("cat {$keyPath}.pub");
    $authorizedKeys = "{$home}/.ssh/authorized_keys";

    // Check if already in authorized_keys
    $alreadyAuthorized = test("grep -q '{$keyComment}' {$authorizedKeys} 2>/dev/null");

    if (! $alreadyAuthorized) {
        run("echo '{$pubKey}' >> {$authorizedKeys}");
        run("chmod 600 {$authorizedKeys}");
        info("Public key added to authorized_keys");
    }

    writeln('');
    writeln('╔═══════════════════════════════════════════════════════════════════════╗');
    writeln('║                    CI/CD KEY GENERATED SUCCESSFULLY                   ║');
    writeln('╚═══════════════════════════════════════════════════════════════════════╝');
    writeln('');
    writeln('IMPORTANT: Copy the private key below to GitHub Secrets.');
    writeln('This is the ONLY time the private key will be displayed.');
    writeln('');
    writeln('GitHub Secret name: SSH_PRIVATE_KEY');
    writeln('');
    writeln('─── PRIVATE KEY (copy everything including BEGIN/END lines) ────────────');
    writeln('');
    writeln(run("cat {$keyPath}"));
    writeln('');
    writeln('─────────────────────────────────────────────────────────────────────────');
    writeln('');
    writeln('Set the secret in GitHub:');
    writeln('  gh secret set SSH_PRIVATE_KEY --env staging < /path/to/saved/key');
    writeln('  gh secret set SSH_PRIVATE_KEY --env production < /path/to/saved/key');
    writeln('');
    writeln('Or via GitHub web UI:');
    writeln('  Repository Settings → Secrets and variables → Actions → Environments');
    writeln('');
})->once();

desc('Show the CI/CD public key');
task('github:ci-key:show', function () {
    $keyPath = getCiKeyPath(public: true);

    if (! test("[ -f {$keyPath} ]")) {
        warning("No CI key found. Run 'github:ci-key' to generate one.");

        return;
    }

    info("CI/CD Public Key ({$keyPath}):");
    writeln('');
    writeln(run("cat {$keyPath}"));
    writeln('');
    info("This key is in the server's authorized_keys file.");
})->once();

desc('Revoke the CI/CD SSH key');
task('github:ci-key:revoke', function () {
    $keyPath = getCiKeyPath();
    $pubKeyPath = getCiKeyPath(public: true);

    if (! test("[ -f {$keyPath} ]")) {
        warning("No CI key found at {$keyPath}");

        return;
    }

    $remoteUser = get('remote_user', 'deployer');
    $home = $remoteUser === 'root' ? '/home/deployer' : run('echo $HOME');
    $authorizedKeys = "{$home}/.ssh/authorized_keys";

    // Get the key comment to find it in authorized_keys
    $pubKey = trim(run("cat {$pubKeyPath}"));

    // Remove from authorized_keys
    if (test("[ -f {$authorizedKeys} ]")) {
        // Create a temp file without the CI key
        run("grep -v 'github-actions@' {$authorizedKeys} > {$authorizedKeys}.tmp || true");
        run("mv {$authorizedKeys}.tmp {$authorizedKeys}");
        run("chmod 600 {$authorizedKeys}");
        info("Removed CI key from authorized_keys");
    }

    // Remove the key files
    run("rm -f {$keyPath} {$pubKeyPath}");
    info("Removed CI key files");

    writeln('');
    info("CI/CD key has been revoked.");
    info("Remember to remove the secret from GitHub Secrets as well.");
    writeln('');
})->once();
