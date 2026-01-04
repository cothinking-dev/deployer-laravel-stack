<?php

namespace Deployer;

desc('Add server deploy key to GitHub via gh CLI');
task('github:deploy-key', function () {
    $repo = get('repository');

    if (! preg_match('#git@github\.com:(.+?)\.git#', $repo, $matches)) {
        warning('Could not parse GitHub repository from: ' . $repo);

        return;
    }

    $repoPath = $matches[1];
    $hostname = run('hostname');
    $keyTitle = "deployer@{$hostname}";

    $remoteUser = get('remote_user', 'deployer');
    $keyPath = $remoteUser === 'root'
        ? '/home/deployer/.ssh/id_ed25519.pub'
        : '~/.ssh/id_ed25519.pub';

    $pubKey = run("cat {$keyPath} 2>/dev/null || echo ''");

    if (empty(trim($pubKey))) {
        warning("No deploy key found at {$keyPath}");

        return;
    }

    info("Adding deploy key to {$repoPath}...");

    $existingKeys = runLocally("gh repo deploy-key list -R {$repoPath} 2>/dev/null || echo ''");

    if (str_contains($existingKeys, $keyTitle)) {
        // Key with same title exists - check if it matches the current key
        // Extract the key fingerprint from the server
        $serverFingerprint = run("ssh-keygen -lf {$keyPath} 2>/dev/null | awk '{print \$2}' || echo ''");

        // Check if the existing key matches by looking for the fingerprint in the key list
        // gh deploy-key list shows: ID, Title, Key (partial), Created
        // We need to compare the actual keys, so delete and re-add if server was reset
        info("Deploy key '{$keyTitle}' already exists, checking if it matches...");

        // Get the key ID for deletion
        $keyId = null;
        foreach (explode("\n", $existingKeys) as $line) {
            if (str_contains($line, $keyTitle)) {
                // First column is the key ID
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
