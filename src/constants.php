<?php

namespace Deployer;

/**
 * Database connection types supported by deployer-laravel-stack.
 *
 * Use these constants instead of magic strings for type safety and IDE support.
 *
 * @example
 * use Deployer\DbConnection;
 *
 * set('db_connection', DbConnection::SQLITE);
 *
 * if ($connection === DbConnection::PGSQL) {
 *     // PostgreSQL-specific logic
 * }
 */
final class DbConnection
{
    public const SQLITE = 'sqlite';
    public const PGSQL = 'pgsql';
    public const MYSQL = 'mysql';

    public const ALL = [
        self::SQLITE,
        self::PGSQL,
        self::MYSQL,
    ];

    public const DEFAULT = self::SQLITE;

    public const PORTS = [
        self::PGSQL => 5432,
        self::MYSQL => 3306,
        self::SQLITE => null,
    ];

    /**
     * Check if a database connection type is valid.
     */
    public static function isValid(string $connection): bool
    {
        return in_array($connection, self::ALL, true);
    }

    /**
     * Get the default port for a database connection type.
     */
    public static function getPort(string $connection): ?int
    {
        return self::PORTS[$connection] ?? null;
    }

    /**
     * Check if a database connection type requires a database server.
     * SQLite does not require a server, PostgreSQL and MySQL do.
     */
    public static function requiresServer(string $connection): bool
    {
        return $connection !== self::SQLITE;
    }

    /**
     * Get human-readable label for a database connection type.
     */
    public static function getLabel(string $connection): string
    {
        return match ($connection) {
            self::SQLITE => 'SQLite',
            self::PGSQL => 'PostgreSQL',
            self::MYSQL => 'MySQL',
            default => $connection,
        };
    }
}

/**
 * Web server types supported by deployer-laravel-stack.
 *
 * @example
 * use Deployer\WebServer;
 *
 * set('web_server', WebServer::FPM);
 *
 * if ($webServer === WebServer::OCTANE) {
 *     // Octane-specific logic
 * }
 */
final class WebServer
{
    public const FPM = 'fpm';
    public const OCTANE = 'octane';

    public const ALL = [
        self::FPM,
        self::OCTANE,
    ];

    public const DEFAULT = self::FPM;

    /**
     * Check if a web server type is valid.
     */
    public static function isValid(string $webServer): bool
    {
        return in_array($webServer, self::ALL, true);
    }

    /**
     * Get human-readable label for a web server type.
     */
    public static function getLabel(string $webServer): string
    {
        return match ($webServer) {
            self::FPM => 'PHP-FPM with Caddy',
            self::OCTANE => 'Laravel Octane with FrankenPHP',
            default => $webServer,
        };
    }
}
