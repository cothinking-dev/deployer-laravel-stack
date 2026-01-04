<?php

namespace Deployer;

use function Deployer\get;
use function Deployer\has;
use function Deployer\run;

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
 * @param array<string, mixed> $env
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
            $value = '"' . addslashes($value) . '"';
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
 * @param array<string> $required
 * @param array<string, mixed> $optional
 * @return array<string, mixed>
 * @throws \RuntimeException If required secrets are missing
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
            'Missing required secrets: ' . implode(', ', $missing) . "\n" .
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
