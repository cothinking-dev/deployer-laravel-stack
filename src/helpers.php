<?php

namespace Deployer;

/**
 * @throws \RuntimeException If no sudo password is configured
 */
function sudo(string $command): string
{
    $secrets = has('secrets') ? get('secrets') : [];
    $pass = $secrets['sudo_pass'] ?? get('sudo_pass') ?? null;

    if ($pass === null) {
        throw new \RuntimeException(
            'No sudo password configured. Set secrets.sudo_pass or sudo_pass option.'
        );
    }

    return run("echo '%secret%' | sudo -S {$command}", secret: $pass);
}

/**
 * @param  array<string, mixed>  $env
 */
function envToString(array $env): string
{
    $content = '';

    foreach ($env as $key => $value) {
        if ($value === null) {
            $value = 'null';
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if (preg_match('/[\s#"\'\$]/', $value) || $value === '') {
            $value = '"'.addslashes($value).'"';
        }

        $content .= "{$key}={$value}\n";
    }

    return $content;
}

function getStage(): string
{
    $labels = get('labels', []);

    return $labels['stage'] ?? 'unknown';
}

/**
 * @throws \RuntimeException If secret is not set
 */
function getSecret(string $key): string
{
    $secrets = has('secrets') ? get('secrets') : [];

    if (! isset($secrets[$key]) || $secrets[$key] === '') {
        throw new \RuntimeException("Missing required secret: {$key}");
    }

    return $secrets[$key];
}

/**
 * @param  array<string>  $required
 * @param  array<string, mixed>  $optional
 *
 * @throws \RuntimeException If required secrets are missing
 *
 * @return array<string, mixed>
 */
function requireSecrets(array $required, array $optional = []): array
{
    $missing = [];
    $secrets = [];

    foreach ($required as $var) {
        $value = getenv($var);
        if ($value === false || $value === '') {
            $missing[] = $var;
        } else {
            $key = strtolower(str_replace('DEPLOYER_', '', $var));
            $secrets[$key] = $value;
        }
    }

    if (! empty($missing)) {
        throw new \RuntimeException(
            'Missing required secrets: '.implode(', ', $missing)."\n".
            'Run deployment via: ./deploy/dep <command> <environment>'
        );
    }

    foreach ($optional as $var => $default) {
        $value = getenv($var);
        $key = strtolower(str_replace('DEPLOYER_', '', $var));
        $secrets[$key] = ($value !== false && $value !== '') ? $value : $default;
    }

    return $secrets;
}

/**
 * Define an environment with sensible defaults.
 *
 * @param  array<string, mixed>  $config
 */
function environment(string $name, array $config): void
{
    $defaults = [
        'app_env' => $name === 'prod' ? 'production' : $name,
        'app_debug' => false,
        'log_level' => 'error',
        'tls_mode' => 'internal',
    ];

    $config = array_merge($defaults, $config);
    $hostname = get('server_hostname');

    host($name)
        ->setHostname($hostname)
        ->set('remote_user', 'deployer')
        ->set('forward_agent', false)
        ->setSshMultiplexing(false)  // Disable multiplexing to improve stability with Deployer v8
        ->set('git_ssh_command', 'ssh -o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes')
        ->set('labels', ['stage' => $name])
        ->set('url', "https://{$config['domain']}")
        ->set('deploy_path', $config['deploy_path'])
        ->set('domain', $config['domain'])
        ->set('db_name', $config['db_name'])
        ->set('redis_db', $config['redis_db'])
        ->set('app_env', $config['app_env'])
        ->set('app_debug', $config['app_debug'])
        ->set('log_level', $config['log_level'])
        ->set('tls_mode', $config['tls_mode'])
        ->set('env_overrides', $config['env'] ?? []);
}

/**
 * Resolve secret placeholders in env values.
 *
 * @param  array<string, mixed>  $env
 * @param  bool  $strict  If true, throw exception on unresolved placeholders
 * @return array<string, mixed>
 *
 * @throws \RuntimeException If strict mode and unresolved placeholder found
 */
function resolveSecrets(array $env, bool $strict = false): array
{
    $secrets = has('secrets') ? get('secrets') : [];
    $unresolved = [];

    $resolved = array_map(function ($value) use ($secrets, &$unresolved) {
        if (is_string($value) && preg_match('/^\{(\w+)\}$/', $value, $matches)) {
            $key = $matches[1];

            if (isset($secrets[$key]) && $secrets[$key] !== '') {
                return $secrets[$key];
            }

            $unresolved[] = $key;

            return $value; // Return original placeholder
        }

        return $value;
    }, $env);

    if ($strict && ! empty($unresolved)) {
        throw new \RuntimeException(
            'Unresolved secret placeholders: {' . implode('}, {', $unresolved) . '}. ' .
            'Ensure these environment variables are set.'
        );
    }

    return $resolved;
}

/**
 * Validate that required configuration keys are set.
 *
 * @param  array<string>  $required  List of required config keys
 *
 * @throws \RuntimeException If required keys are missing
 */
function validateConfig(array $required): void
{
    $missing = [];

    foreach ($required as $key) {
        if (! has($key) || get($key) === null || get($key) === '') {
            $missing[] = $key;
        }
    }

    if (! empty($missing)) {
        throw new \RuntimeException(
            'Missing required configuration: ' . implode(', ', $missing)
        );
    }
}

/**
 * Check if a service is running on the remote server.
 */
function isServiceRunning(string $service): bool
{
    $status = run("systemctl is-active {$service} 2>/dev/null || echo 'inactive'");

    return trim($status) === 'active';
}

/**
 * Wait for a service to become active with timeout.
 *
 * @param  int  $timeoutSeconds  Maximum time to wait
 * @return bool True if service became active, false if timeout
 */
function waitForService(string $service, int $timeoutSeconds = 30): bool
{
    $startTime = time();

    while ((time() - $startTime) < $timeoutSeconds) {
        if (isServiceRunning($service)) {
            return true;
        }

        run('sleep 1');
    }

    return false;
}

/**
 * Get disk space available at path in MB.
 */
function getDiskSpaceMb(string $path): int
{
    $output = run("df -BM {$path} 2>/dev/null | tail -1 | awk '{print \$4}' | tr -d 'M'");

    return (int) trim($output);
}

/**
 * Get available memory in MB.
 */
function getAvailableMemoryMb(): int
{
    $output = run("free -m | awk '/^Mem:/ {print \$7}'");

    return (int) trim($output);
}

/**
 * Safely run a command with retry logic.
 *
 * @param  int  $maxAttempts  Maximum number of attempts
 * @param  int  $delaySeconds  Delay between attempts
 * @return string Command output
 *
 * @throws \RuntimeException If all attempts fail
 */
function runWithRetry(string $command, int $maxAttempts = 3, int $delaySeconds = 2): string
{
    $lastException = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return run($command);
        } catch (\Throwable $e) {
            $lastException = $e;

            if ($attempt < $maxAttempts) {
                warning("Attempt {$attempt}/{$maxAttempts} failed, retrying in {$delaySeconds}s...");
                run("sleep {$delaySeconds}");
            }
        }
    }

    throw new \RuntimeException(
        "Command failed after {$maxAttempts} attempts: " . $lastException->getMessage()
    );
}
