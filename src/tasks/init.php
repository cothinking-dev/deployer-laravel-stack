<?php

namespace Deployer;

require_once __DIR__.'/templates.php';

desc('Initialize deployment configuration with interactive wizard');
task('init', function () {
    writeln('');
    writeln('<fg=cyan>╔════════════════════════════════════════════════════════════╗</>');
    writeln('<fg=cyan>║       Deployer Laravel Stack - Configuration Wizard        ║</>');
    writeln('<fg=cyan>╚════════════════════════════════════════════════════════════╝</>');
    writeln('');

    // 1.2 Check project root
    if (!file_exists('composer.json')) {
        throw new \RuntimeException(
            'Run from project root (where composer.json is)'
        );
    }

    // 1.3 Step 1: Project details
    writeln('<fg=yellow>Step 1: Project Details</>');
    writeln('');

    $appName = ask('Application name', basename(getcwd()));
    $repository = ask('Git repository (SSH URL)', 'git@github.com:your-org/your-repo.git');
    $serverHostname = ask('Server hostname', 'your-server.example.com');
    $prodDomain = ask('Production domain', 'example.com');

    // 1.4 Step 2: Optional staging
    writeln('');
    writeln('<fg=yellow>Step 2: Staging Environment</>');
    writeln('');

    $hasStaging = askConfirmation('Do you want a staging environment?', true);
    $stagingDomain = '';
    if ($hasStaging) {
        $stagingDomain = ask('Staging domain', 'staging.' . $prodDomain);
    }

    // 1.5 Step 3: Stack selection
    writeln('');
    writeln('<fg=yellow>Step 3: Stack Configuration</>');
    writeln('');

    $database = askChoice('Database', [
        'sqlite' => 'SQLite (recommended, zero config)',
        'pgsql' => 'PostgreSQL (for larger scale)',
        'mysql' => 'MySQL',
    ], 'sqlite');

    $queue = askChoice('Queue driver', [
        'redis' => 'Redis (recommended)',
        'database' => 'Database',
        'none' => 'None (sync)',
    ], 'redis');

    // 1.6 Step 4: Web server
    writeln('');
    writeln('<fg=yellow>Step 4: Web Server</>');
    writeln('');

    $webServer = askChoice('Web server', [
        'fpm' => 'PHP-FPM with Caddy (traditional)',
        'octane' => 'Laravel Octane with FrankenPHP (high performance)',
    ], 'fpm');

    // 1.7 Step 5: Secrets management
    writeln('');
    writeln('<fg=yellow>Step 5: Secrets Management</>');
    writeln('');

    $secretsMode = askChoice('How do you want to manage secrets?', [
        'github-actions' => 'GitHub Actions CI/CD (recommended for teams)',
        '1password' => '1Password CLI (for local development)',
        'env' => 'Plain .env file (simple)',
    ], 'github-actions');

    // 1.8 Step 6: 1Password config
    $opVault = 'DevOps';
    $opItem = '';
    if ($secretsMode === '1password') {
        writeln('');
        writeln('<fg=yellow>Step 6: 1Password Configuration</>');
        writeln('');

        $opVault = ask('1Password vault name', 'DevOps');
        $opItem = ask('1Password item name', strtolower(str_replace([' ', '_'], '-', $appName)));
    }

    // Build config array
    $config = [
        'app_name' => $appName,
        'repository' => $repository,
        'server_hostname' => $serverHostname,
        'prod_domain' => $prodDomain,
        'has_staging' => $hasStaging,
        'staging_domain' => $stagingDomain,
        'database' => $database,
        'queue' => $queue,
        'web_server' => $webServer,
        'secrets_mode' => $secretsMode,
        'op_vault' => $opVault,
        'op_item' => $opItem,
    ];

    // 1.9 File existence checks with overwrite confirmation
    writeln('');
    writeln('<fg=yellow>Generating Files</>');
    writeln('');

    $files = [
        'deploy.php' => generateDeployPhp($config),
        'deploy/dep' => generateDepWrapper($secretsMode),
    ];

    if ($secretsMode === '1password') {
        $files['deploy/secrets.tpl'] = generateSecretsTpl($config);
    } elseif ($secretsMode === 'github-actions') {
        $files['deploy/secrets.env.example'] = generateSecretsEnvExample($config);
        $files['.github/workflows/deploy.yml'] = generateGitHubActionsWorkflow($config);
    } else {
        $files['deploy/secrets.env'] = generateSecretsEnv($config);
    }

    // Create deploy directory if needed
    if (!is_dir('deploy')) {
        mkdir('deploy', 0755, true);
        info('Created deploy/ directory');
    }

    // Create .github/workflows directory if needed (for GitHub Actions mode)
    if ($secretsMode === 'github-actions' && !is_dir('.github/workflows')) {
        mkdir('.github/workflows', 0755, true);
        info('Created .github/workflows/ directory');
    }

    foreach ($files as $path => $content) {
        $write = true;

        if (file_exists($path)) {
            $write = askConfirmation("Overwrite existing {$path}?", false);
        }

        if ($write) {
            file_put_contents($path, $content);
            info("Created {$path}");

            // Make wrapper executable
            if ($path === 'deploy/dep') {
                chmod($path, 0755);
            }
        } else {
            writeln("  Skipped {$path}");
        }
    }

    // Update .gitignore for env mode
    if ($secretsMode === 'env') {
        $gitignore = file_exists('.gitignore') ? file_get_contents('.gitignore') : '';
        if (strpos($gitignore, 'deploy/secrets.env') === false) {
            $gitignore = rtrim($gitignore) . "\n\n# Deployment secrets (never commit)\ndeploy/secrets.env\n";
            file_put_contents('.gitignore', $gitignore);
            info('Added deploy/secrets.env to .gitignore');
        }
    }

    // 1.10 Post-generation "next steps"
    writeln('');
    writeln('<fg=green>╔════════════════════════════════════════════════════════════╗</>');
    writeln('<fg=green>║                    Setup Complete!                         ║</>');
    writeln('<fg=green>╚════════════════════════════════════════════════════════════╝</>');
    writeln('');
    writeln('<fg=white>Next steps:</>');
    writeln('');

    if ($secretsMode === 'github-actions') {
        writeln('  1. Bootstrap your server (one-time setup):');
        writeln('       ./deploy/dep setup:server server');
        writeln('');
        writeln('  2. Generate CI/CD SSH key for GitHub Actions:');
        writeln('       ./deploy/dep github:ci-key server');
        writeln('');
        writeln('  3. (Optional) Whitelist GitHub IPs in Hetzner Cloud Firewall:');
        writeln('       ./deploy/dep hcloud:github-ips server');
        writeln('');
        writeln('  4. Create GitHub environments: staging, production');
        writeln('       Repository Settings → Environments → New environment');
        writeln('');
        writeln('  5. Set GitHub Secrets for each environment:');
        writeln('       gh secret set SSH_PRIVATE_KEY --env staging < /path/to/key');
        writeln('       gh secret set SSH_PRIVATE_KEY --env production < /path/to/key');
        writeln('       gh secret set DEPLOYER_SUDO_PASS --env staging');
        writeln('       gh secret set DEPLOYER_SUDO_PASS --env production');
        writeln('       gh secret set DEPLOYER_APP_KEY --env staging');
        writeln('       gh secret set DEPLOYER_APP_KEY --env production');
        if ($database !== 'sqlite') {
            writeln('       gh secret set DEPLOYER_DB_PASSWORD --env staging');
            writeln('       gh secret set DEPLOYER_DB_PASSWORD --env production');
        }
        writeln('');
        writeln('  6. Push to GitHub and deployments will run automatically:');
        writeln('       - Push to develop → Deploy to staging');
        writeln('       - Push to main → Deploy to production');
        writeln('');
        writeln('  See deploy/secrets.env.example for all available secrets.');
    } elseif ($secretsMode === '1password') {
        writeln('  1. Create a 1Password item in your "' . $opVault . '" vault named "' . $opItem . '"');
        writeln('     with these fields:');
        writeln('       - sudo-password');
        if ($database !== 'sqlite') {
            writeln('       - db-password');
        }
        writeln('       - prod-app-key (generate with: php artisan key:generate --show)');
        if ($hasStaging) {
            writeln('       - staging-app-key');
        }
        writeln('');
        writeln('  2. Review and customize deploy.php');
        writeln('');
        writeln('  3. Bootstrap your server:');
        writeln('       ./deploy/dep setup:server server');
        writeln('');
        writeln('  4. Provision and deploy production:');
        writeln('       ./deploy/dep setup:environment prod');
        writeln('');
        writeln('  5. (Optional) Migrate existing data (media, SQLite database):');
        writeln('       ./deploy/dep data:migrate prod');
    } else {
        writeln('  1. Edit deploy/secrets.env and fill in the placeholder values');
        writeln('');
        writeln('  2. Review and customize deploy.php');
        writeln('');
        writeln('  3. Bootstrap your server:');
        writeln('       ./deploy/dep setup:server server');
        writeln('');
        writeln('  4. Provision and deploy production:');
        writeln('       ./deploy/dep setup:environment prod');
        writeln('');
        writeln('  5. (Optional) Migrate existing data (media, SQLite database):');
        writeln('       ./deploy/dep data:migrate prod');
        writeln('');
        writeln('<fg=yellow>  ⚠ Security warning: deploy/secrets.env contains sensitive data.</>');
        writeln('<fg=yellow>    Make sure it is in .gitignore and never committed.</>');
    }

    writeln('');
    writeln('Run <fg=cyan>vendor/bin/dep init:check</> to validate your configuration.');
    writeln('');
});

// 3.1-3.8 Validation task
desc('Validate deployment configuration');
task('init:check', function () {
    $issues = [];
    $warnings = [];

    writeln('');
    writeln('<fg=cyan>Checking deployment configuration...</>');
    writeln('');

    // 3.2 Validate deploy.php exists and has valid PHP syntax
    if (!file_exists('deploy.php')) {
        $issues[] = 'deploy.php not found';
    } else {
        $output = [];
        $exitCode = 0;
        exec('php -l deploy.php 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $issues[] = 'deploy.php has syntax errors: ' . implode(' ', $output);
        } else {
            writeln('  <fg=green>✓</> deploy.php syntax valid');
        }
    }

    // 3.3 Validate deploy/dep wrapper exists and is executable
    if (!file_exists('deploy/dep')) {
        $issues[] = 'deploy/dep wrapper not found';
    } elseif (!is_executable('deploy/dep')) {
        $issues[] = 'deploy/dep is not executable (run: chmod +x deploy/dep)';
    } else {
        writeln('  <fg=green>✓</> deploy/dep wrapper found and executable');
    }

    // 3.4-3.5 Validate secrets file
    $hasTpl = file_exists('deploy/secrets.tpl');
    $hasEnv = file_exists('deploy/secrets.env');

    if ($hasTpl && $hasEnv) {
        // 3.5 Both exist = error
        $issues[] = 'Both deploy/secrets.tpl and deploy/secrets.env exist. Remove one.';
    } elseif (!$hasTpl && !$hasEnv) {
        // 3.4 Neither exists
        $issues[] = 'No secrets file found (need deploy/secrets.tpl or deploy/secrets.env)';
    } elseif ($hasTpl) {
        writeln('  <fg=green>✓</> deploy/secrets.tpl found (1Password mode)');
    } elseif ($hasEnv) {
        writeln('  <fg=green>✓</> deploy/secrets.env found (env mode)');

        // 3.6 Check for placeholder values
        $content = file_get_contents('deploy/secrets.env');
        if (preg_match('/=(your-|placeholder|changeme|xxx)/i', $content)) {
            $warnings[] = 'deploy/secrets.env contains placeholder values';
        }

        // 3.7 Check .gitignore
        $gitignore = file_exists('.gitignore') ? file_get_contents('.gitignore') : '';
        if (strpos($gitignore, 'deploy/secrets.env') === false) {
            $warnings[] = 'deploy/secrets.env is not in .gitignore (security risk!)';
        }
    }

    // Display warnings
    foreach ($warnings as $warning) {
        writeln("  <fg=yellow>⚠</> {$warning}");
    }

    // Display issues
    foreach ($issues as $issue) {
        writeln("  <fg=red>✗</> {$issue}");
    }

    writeln('');

    // 3.8 Exit codes
    if (!empty($issues)) {
        writeln('<fg=red>Configuration validation failed.</>');
        exit(1);
    } elseif (!empty($warnings)) {
        writeln('<fg=yellow>Configuration valid with warnings.</>');
        exit(0);
    } else {
        writeln('<fg=green>Configuration valid.</>');
        exit(0);
    }
});
