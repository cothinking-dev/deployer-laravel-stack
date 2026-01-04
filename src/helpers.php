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
