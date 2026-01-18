<?php

namespace Deployer\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Deployer\DbConnection;
use Deployer\WebServer;

// Include the constants file directly for testing
require_once __DIR__ . '/../src/constants.php';

class ConstantsTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // DbConnection tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function dbConnectionConstantsHaveExpectedValues(): void
    {
        $this->assertEquals('sqlite', DbConnection::SQLITE);
        $this->assertEquals('pgsql', DbConnection::PGSQL);
        $this->assertEquals('mysql', DbConnection::MYSQL);
    }

    #[Test]
    public function dbConnectionDefaultIsSqlite(): void
    {
        $this->assertEquals(DbConnection::SQLITE, DbConnection::DEFAULT);
    }

    #[Test]
    public function dbConnectionAllContainsAllTypes(): void
    {
        $this->assertContains(DbConnection::SQLITE, DbConnection::ALL);
        $this->assertContains(DbConnection::PGSQL, DbConnection::ALL);
        $this->assertContains(DbConnection::MYSQL, DbConnection::ALL);
        $this->assertCount(3, DbConnection::ALL);
    }

    #[Test]
    #[DataProvider('validDbConnectionsProvider')]
    public function dbConnectionIsValidReturnsTrueForValidConnections(string $connection): void
    {
        $this->assertTrue(DbConnection::isValid($connection));
    }

    public static function validDbConnectionsProvider(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'pgsql' => ['pgsql'],
            'mysql' => ['mysql'],
        ];
    }

    #[Test]
    #[DataProvider('invalidDbConnectionsProvider')]
    public function dbConnectionIsValidReturnsFalseForInvalidConnections(string $connection): void
    {
        $this->assertFalse(DbConnection::isValid($connection));
    }

    public static function invalidDbConnectionsProvider(): array
    {
        return [
            'postgres (wrong name)' => ['postgres'],
            'postgresql (wrong name)' => ['postgresql'],
            'mariadb' => ['mariadb'],
            'empty' => [''],
            'random' => ['random'],
        ];
    }

    #[Test]
    public function dbConnectionGetPortReturnsCorrectPorts(): void
    {
        $this->assertEquals(5432, DbConnection::getPort(DbConnection::PGSQL));
        $this->assertEquals(3306, DbConnection::getPort(DbConnection::MYSQL));
        $this->assertNull(DbConnection::getPort(DbConnection::SQLITE));
    }

    #[Test]
    public function dbConnectionGetPortReturnsNullForUnknown(): void
    {
        $this->assertNull(DbConnection::getPort('unknown'));
    }

    #[Test]
    public function dbConnectionRequiresServerReturnsFalseForSqlite(): void
    {
        $this->assertFalse(DbConnection::requiresServer(DbConnection::SQLITE));
    }

    #[Test]
    public function dbConnectionRequiresServerReturnsTrueForServerDatabases(): void
    {
        $this->assertTrue(DbConnection::requiresServer(DbConnection::PGSQL));
        $this->assertTrue(DbConnection::requiresServer(DbConnection::MYSQL));
    }

    #[Test]
    public function dbConnectionGetLabelReturnsHumanReadableLabels(): void
    {
        $this->assertEquals('SQLite', DbConnection::getLabel(DbConnection::SQLITE));
        $this->assertEquals('PostgreSQL', DbConnection::getLabel(DbConnection::PGSQL));
        $this->assertEquals('MySQL', DbConnection::getLabel(DbConnection::MYSQL));
    }

    #[Test]
    public function dbConnectionGetLabelReturnsInputForUnknown(): void
    {
        $this->assertEquals('unknown', DbConnection::getLabel('unknown'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WebServer tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function webServerConstantsHaveExpectedValues(): void
    {
        $this->assertEquals('fpm', WebServer::FPM);
        $this->assertEquals('octane', WebServer::OCTANE);
    }

    #[Test]
    public function webServerDefaultIsFpm(): void
    {
        $this->assertEquals(WebServer::FPM, WebServer::DEFAULT);
    }

    #[Test]
    public function webServerAllContainsAllTypes(): void
    {
        $this->assertContains(WebServer::FPM, WebServer::ALL);
        $this->assertContains(WebServer::OCTANE, WebServer::ALL);
        $this->assertCount(2, WebServer::ALL);
    }

    #[Test]
    #[DataProvider('validWebServersProvider')]
    public function webServerIsValidReturnsTrueForValidServers(string $webServer): void
    {
        $this->assertTrue(WebServer::isValid($webServer));
    }

    public static function validWebServersProvider(): array
    {
        return [
            'fpm' => ['fpm'],
            'octane' => ['octane'],
        ];
    }

    #[Test]
    #[DataProvider('invalidWebServersProvider')]
    public function webServerIsValidReturnsFalseForInvalidServers(string $webServer): void
    {
        $this->assertFalse(WebServer::isValid($webServer));
    }

    public static function invalidWebServersProvider(): array
    {
        return [
            'nginx' => ['nginx'],
            'apache' => ['apache'],
            'swoole' => ['swoole'],
            'empty' => [''],
        ];
    }

    #[Test]
    public function webServerGetLabelReturnsHumanReadableLabels(): void
    {
        $this->assertEquals('PHP-FPM with Caddy', WebServer::getLabel(WebServer::FPM));
        $this->assertEquals('Laravel Octane with FrankenPHP', WebServer::getLabel(WebServer::OCTANE));
    }

    #[Test]
    public function webServerGetLabelReturnsInputForUnknown(): void
    {
        $this->assertEquals('unknown', WebServer::getLabel('unknown'));
    }
}
