<?php

namespace Deployer;

/**
 * Hetzner Cloud Firewall Management for GitHub Actions
 *
 * These tasks configure Hetzner Cloud Firewall to allow SSH access from GitHub Actions runners.
 * Requires: hcloud CLI installed and configured (hcloud context create)
 *
 * Usage:
 *   dep hcloud:github-ips server    # Create/update firewall with GitHub IPs
 *   dep hcloud:github-ips:show      # Show current firewall rules
 *   dep hcloud:github-ips:refresh   # Update GitHub IP ranges
 */

/**
 * Get the firewall name for this project.
 */
function getFirewallName(): string
{
    $app = get('application', 'laravel-app');
    $safeName = preg_replace('/[^a-zA-Z0-9-]/', '-', strtolower($app));

    return "github-actions-{$safeName}";
}

/**
 * Fetch GitHub Actions IP ranges from GitHub's meta API.
 *
 * @return array{ipv4: string[], ipv6: string[]}
 */
function fetchGitHubActionsIPs(): array
{
    $json = runLocally('curl -s https://api.github.com/meta');
    $meta = json_decode($json, true);

    if (! isset($meta['actions'])) {
        throw new \RuntimeException('Could not fetch GitHub Actions IP ranges from api.github.com/meta');
    }

    $ipv4 = [];
    $ipv6 = [];

    foreach ($meta['actions'] as $cidr) {
        if (str_contains($cidr, ':')) {
            $ipv6[] = $cidr;
        } else {
            $ipv4[] = $cidr;
        }
    }

    return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
}

/**
 * Check if hcloud CLI is installed and configured.
 */
function checkHcloudCli(): bool
{
    $result = runLocally('command -v hcloud 2>/dev/null && hcloud context active 2>/dev/null || echo "NOT_CONFIGURED"');

    return ! str_contains($result, 'NOT_CONFIGURED');
}

desc('Configure Hetzner Cloud Firewall for GitHub Actions SSH access');
task('hcloud:github-ips', function () {
    if (! checkHcloudCli()) {
        warning('hcloud CLI not installed or not configured.');
        writeln('');
        writeln('Install hcloud CLI:');
        writeln('  brew install hcloud');
        writeln('');
        writeln('Configure hcloud:');
        writeln('  hcloud context create <project-name>');
        writeln('  # Enter your Hetzner Cloud API token when prompted');
        writeln('');

        return;
    }

    $firewallName = getFirewallName();
    info("Firewall name: {$firewallName}");

    // Fetch GitHub IPs
    info('Fetching GitHub Actions IP ranges...');
    $ips = fetchGitHubActionsIPs();

    $ipv4Count = count($ips['ipv4']);
    $ipv6Count = count($ips['ipv6']);
    info("Found {$ipv4Count} IPv4 and {$ipv6Count} IPv6 ranges");

    // Check if firewall exists
    $existingFirewall = runLocally("hcloud firewall list -o noheader | grep -w '{$firewallName}' || echo ''");

    if (empty(trim($existingFirewall))) {
        // Create new firewall
        info("Creating firewall: {$firewallName}");
        runLocally("hcloud firewall create --name {$firewallName}");
    } else {
        info("Firewall exists, updating rules...");
        // Delete existing rules
        $rules = runLocally("hcloud firewall describe {$firewallName} -o json | jq -r '.rules | length'");

        if ((int) $rules > 0) {
            // Remove all existing rules by recreating with empty rules
            runLocally("hcloud firewall delete-rule {$firewallName} --direction in --port 22 --protocol tcp 2>/dev/null || true");
        }
    }

    // Build rules JSON for all GitHub IPs
    // Hetzner firewall rules support multiple source IPs per rule
    $allIPs = array_merge($ips['ipv4'], $ips['ipv6']);

    // Hetzner has a limit on the number of source IPs per rule, so we need to batch
    $batchSize = 100; // Hetzner allows ~100 CIDRs per rule
    $batches = array_chunk($allIPs, $batchSize);

    foreach ($batches as $index => $batch) {
        $sourceIPs = implode(',', $batch);
        $description = "github-actions-batch-" . ($index + 1);

        info("Adding rule batch " . ($index + 1) . "/" . count($batches) . " (" . count($batch) . " IPs)");

        runLocally("hcloud firewall add-rule {$firewallName} --direction in --port 22 --protocol tcp --source-ips {$sourceIPs} --description '{$description}'");
    }

    // Get server name
    $host = get('hostname');
    $serverName = runLocally("hcloud server list -o noheader | grep -w '{$host}' | awk '{print \$2}' || echo ''");

    if (! empty(trim($serverName))) {
        // Apply firewall to server
        $appliedFirewalls = runLocally("hcloud server describe {$serverName} -o json | jq -r '.firewalls[].firewall.name' 2>/dev/null || echo ''");

        if (! str_contains($appliedFirewalls, $firewallName)) {
            info("Applying firewall to server: {$serverName}");
            runLocally("hcloud firewall apply-to-resource {$firewallName} --type server --server {$serverName}");
        } else {
            info("Firewall already applied to server: {$serverName}");
        }
    } else {
        warning("Could not find server by hostname. Apply firewall manually:");
        writeln("  hcloud firewall apply-to-resource {$firewallName} --type server --server <server-name>");
    }

    writeln('');
    writeln('╔═══════════════════════════════════════════════════════════════════════╗');
    writeln('║              HETZNER FIREWALL CONFIGURED SUCCESSFULLY                 ║');
    writeln('╚═══════════════════════════════════════════════════════════════════════╝');
    writeln('');
    writeln("Firewall: {$firewallName}");
    writeln("Rules: " . count($batches) . " batch(es) with " . count($allIPs) . " total GitHub IP ranges");
    writeln('');
    writeln('IMPORTANT: GitHub IPs change periodically. Run this task monthly:');
    writeln('  dep hcloud:github-ips:refresh server');
    writeln('');
})->once();

desc('Show current Hetzner Cloud Firewall rules');
task('hcloud:github-ips:show', function () {
    if (! checkHcloudCli()) {
        warning('hcloud CLI not installed or not configured.');

        return;
    }

    $firewallName = getFirewallName();

    // Check if firewall exists
    $exists = runLocally("hcloud firewall list -o noheader | grep -w '{$firewallName}' || echo ''");

    if (empty(trim($exists))) {
        warning("Firewall '{$firewallName}' does not exist.");
        info("Run 'hcloud:github-ips' to create it.");

        return;
    }

    info("Firewall: {$firewallName}");
    writeln('');
    writeln(runLocally("hcloud firewall describe {$firewallName}"));
})->once();

desc('Refresh GitHub Actions IP ranges in Hetzner Cloud Firewall');
task('hcloud:github-ips:refresh', function () {
    info('Refreshing GitHub Actions IP ranges...');
    invoke('hcloud:github-ips');
})->once();

desc('Remove GitHub Actions firewall');
task('hcloud:github-ips:remove', function () {
    if (! checkHcloudCli()) {
        warning('hcloud CLI not installed or not configured.');

        return;
    }

    $firewallName = getFirewallName();

    // Check if firewall exists
    $exists = runLocally("hcloud firewall list -o noheader | grep -w '{$firewallName}' || echo ''");

    if (empty(trim($exists))) {
        warning("Firewall '{$firewallName}' does not exist.");

        return;
    }

    // Remove from all servers first
    $servers = runLocally("hcloud firewall describe {$firewallName} -o json | jq -r '.applied_to[].server.name // empty' 2>/dev/null || echo ''");

    foreach (explode("\n", $servers) as $server) {
        $server = trim($server);

        if (! empty($server)) {
            info("Removing firewall from server: {$server}");
            runLocally("hcloud firewall remove-from-resource {$firewallName} --type server --server {$server}");
        }
    }

    // Delete firewall
    info("Deleting firewall: {$firewallName}");
    runLocally("hcloud firewall delete {$firewallName}");

    info('Firewall removed successfully.');
})->once();
