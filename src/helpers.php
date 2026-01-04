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
 * @return array<string, mixed>
 */
function resolveSecrets(array $env): array
{
    $secrets = has('secrets') ? get('secrets') : [];

    return array_map(function ($value) use ($secrets) {
        if (is_string($value) && preg_match('/^\{(\w+)\}$/', $value, $matches)) {
            return $secrets[$matches[1]] ?? $value;
        }

        return $value;
    }, $env);
}
