<?php

namespace Deployer\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

// Include the helpers file directly for testing
require_once __DIR__ . '/../src/helpers.php';

use function Deployer\validateShellInput;
use function Deployer\validateDomain;
use function Deployer\validateDbName;
use function Deployer\validateUsername;
use function Deployer\validateDeployPath;

class ValidationTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // validateShellInput tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function validateShellInputAcceptsValidAlphanumeric(): void
    {
        $this->assertEquals('test123', validateShellInput('test123', 'test'));
        $this->assertEquals('my-value', validateShellInput('my-value', 'test'));
        $this->assertEquals('my_value', validateShellInput('my_value', 'test'));
        $this->assertEquals('my.value', validateShellInput('my.value', 'test'));
    }

    #[Test]
    public function validateShellInputRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty test is not allowed');
        validateShellInput('', 'test');
    }

    #[Test]
    public function validateShellInputRejectsInvalidCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validateShellInput('test; rm -rf /', 'test');
    }

    #[Test]
    public function validateShellInputRejectsSpecialChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validateShellInput('test$var', 'test');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // validateDomain tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('validDomainsProvider')]
    public function validateDomainAcceptsValidDomains(string $domain): void
    {
        $this->assertEquals($domain, validateDomain($domain));
    }

    public static function validDomainsProvider(): array
    {
        return [
            'simple domain' => ['example.com'],
            'subdomain' => ['sub.example.com'],
            'deep subdomain' => ['a.b.c.example.com'],
            'localhost' => ['localhost'],
            'test tld' => ['myapp.test'],
            'with hyphen' => ['my-app.example.com'],
            'with numbers' => ['app123.example.com'],
            'single char labels' => ['a.b.c'],
        ];
    }

    #[Test]
    #[DataProvider('invalidDomainsProvider')]
    public function validateDomainRejectsInvalidDomains(string $domain): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validateDomain($domain);
    }

    public static function invalidDomainsProvider(): array
    {
        return [
            'command injection' => ['example.com; rm -rf /'],
            'shell variable' => ['$HOME.example.com'],
            'backticks' => ['`whoami`.example.com'],
            'spaces' => ['example .com'],
            'starts with hyphen' => ['-example.com'],
            'ends with hyphen' => ['example-.com'],
            'double dot' => ['example..com'],
            'empty' => [''],
        ];
    }

    #[Test]
    public function validateDomainRejectsTooLongDomain(): void
    {
        $longDomain = str_repeat('a', 254) . '.com';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too long');
        validateDomain($longDomain);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // validateDbName tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('validDbNamesProvider')]
    public function validateDbNameAcceptsValidNames(string $name): void
    {
        $this->assertEquals($name, validateDbName($name));
    }

    public static function validDbNamesProvider(): array
    {
        return [
            'simple' => ['myapp'],
            'with underscore' => ['my_app'],
            'with numbers' => ['myapp123'],
            'starts with underscore' => ['_myapp'],
            'staging suffix' => ['myapp_staging'],
            'uppercase' => ['MyApp'],
        ];
    }

    #[Test]
    #[DataProvider('invalidDbNamesProvider')]
    public function validateDbNameRejectsInvalidNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validateDbName($name);
    }

    public static function invalidDbNamesProvider(): array
    {
        return [
            'sql injection' => ['myapp; DROP TABLE users;--'],
            'starts with number' => ['123myapp'],
            'with hyphen' => ['my-app'],
            'with space' => ['my app'],
            'with semicolon' => ['myapp;'],
            'empty' => [''],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // validateUsername tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('validUsernamesProvider')]
    public function validateUsernameAcceptsValidUsernames(string $username): void
    {
        $this->assertEquals($username, validateUsername($username));
    }

    public static function validUsernamesProvider(): array
    {
        return [
            'simple' => ['deployer'],
            'with underscore' => ['deploy_user'],
            'with hyphen' => ['deploy-user'],
            'with numbers' => ['deployer123'],
            'starts with underscore' => ['_deployer'],
        ];
    }

    #[Test]
    #[DataProvider('invalidUsernamesProvider')]
    public function validateUsernameRejectsInvalidUsernames(string $username): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validateUsername($username);
    }

    public static function invalidUsernamesProvider(): array
    {
        return [
            'command injection' => ['deployer; rm -rf /'],
            'starts with number' => ['123deployer'],
            'uppercase' => ['Deployer'],
            'with space' => ['deploy user'],
            'with dot' => ['deploy.user'],
            'empty' => [''],
        ];
    }

    #[Test]
    public function validateUsernameRejectsTooLongUsername(): void
    {
        $longUsername = str_repeat('a', 33);
        $this->expectException(\InvalidArgumentException::class);
        validateUsername($longUsername);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // validateDeployPath tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('validDeployPathsProvider')]
    public function validateDeployPathAcceptsValidPaths(string $path): void
    {
        $this->assertEquals($path, validateDeployPath($path));
    }

    public static function validDeployPathsProvider(): array
    {
        return [
            'absolute home path' => ['/home/deployer/myapp'],
            'tilde path' => ['~/myapp'],
            'var www path' => ['/var/www/myapp'],
            'with underscore' => ['/home/deployer/my_app'],
            'with hyphen' => ['/home/deployer/my-app'],
            'deep path' => ['/home/deployer/apps/prod/myapp'],
            'with dots in name' => ['/home/deployer/myapp.com'],
        ];
    }

    #[Test]
    #[DataProvider('invalidDeployPathsProvider')]
    public function validateDeployPathRejectsInvalidPaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validateDeployPath($path);
    }

    public static function invalidDeployPathsProvider(): array
    {
        return [
            'path traversal' => ['/home/deployer/../../../etc/passwd'],
            'command injection' => ['/home/deployer/myapp; rm -rf /'],
            'shell variable' => ['/home/$USER/myapp'],
            'backticks' => ['/home/`whoami`/myapp'],
            'relative path' => ['myapp'],
            'empty' => [''],
        ];
    }
}
