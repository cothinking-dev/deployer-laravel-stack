# OpenSpec Proposal: Security & Quality Improvements

**Spec ID**: OPENSPEC-001
**Date**: 2026-01-18
**Status**: Implemented
**Author**: DevOps Review
**Priority**: High (Security), Medium (Quality)
**Implemented**: 2026-01-18

---

## Abstract

This proposal addresses security vulnerabilities, code quality issues, and usability improvements identified in a comprehensive DevOps review of deployer-laravel-stack. The changes fall into three categories:

1. **Security hardening** - Tighten sudo wildcards, add input validation
2. **Code quality** - Constants, consistent error handling, file splitting
3. **Default changes** - SQLite as default database (simpler onboarding)

---

## Motivation

### Security Concerns

The current sudo allowlist in `bootstrap.php` contains overly permissive wildcards:

```php
'/bin/mv * /usr/local/bin/*',   // Can move ANY file to /usr/local/bin
'/usr/bin/tee *',               // Can overwrite ANY file
'/bin/sed *',                   // Can modify ANY file
'/bin/bash /tmp/*.sh',          // Can execute ANY script in /tmp
```

These patterns could be exploited if an attacker gains access to the deployer user.

### Code Quality Issues

- Magic strings for database types (`'sqlite'`, `'pgsql'`, `'mysql'`)
- Large monolithic files (env.php at 8.2K, migrate.php at 14K)
- Inconsistent error handling (some throw, some return silently)
- No PHP unit tests

### Default Database

PostgreSQL is currently the default, but SQLite is simpler for:
- New Laravel projects
- Development environments
- Small production apps
- Zero external dependencies

---

## Specification

### 1. Security: Tighten Sudo Wildcards

**File**: `src/provision/bootstrap.php`
**Priority**: High
**Risk if not addressed**: Medium (privilege escalation vector)

#### Current (Insecure)

```php
set('sudo_allowed_commands', [
    // ...
    '/bin/mv * /usr/local/bin/*',
    '/bin/mv /tmp/* /etc/caddy/*',
    '/bin/mv /tmp/* /etc/fail2ban/*',
    '/usr/bin/tee *',
    '/bin/sed *',
    '/bin/bash /tmp/*.sh',
    '/bin/cat /etc/*',
    '/usr/bin/find *',
]);
```

#### Proposed (Hardened)

```php
set('sudo_allowed_commands', [
    // ─────────────────────────────────────────────────────────────────────
    // Service Management (unchanged - already specific)
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/systemctl restart php*-fpm',
    '/usr/bin/systemctl reload php*-fpm',
    '/usr/bin/systemctl start php*-fpm',
    '/usr/bin/systemctl stop php*-fpm',
    '/usr/bin/systemctl status php*-fpm',

    '/usr/bin/systemctl restart caddy',
    '/usr/bin/systemctl reload caddy',
    '/usr/bin/systemctl start caddy',
    '/usr/bin/systemctl stop caddy',
    '/usr/bin/systemctl status caddy',
    '/usr/bin/caddy reload --config /etc/caddy/Caddyfile',
    '/usr/bin/caddy validate --config /etc/caddy/Caddyfile',
    '/usr/bin/caddy fmt --overwrite /etc/caddy/Caddyfile',

    '/usr/bin/systemctl restart postgresql',
    '/usr/bin/systemctl reload postgresql',
    '/usr/bin/systemctl status postgresql',

    '/usr/bin/systemctl restart mysql',
    '/usr/bin/systemctl reload mysql',
    '/usr/bin/systemctl status mysql',

    '/usr/bin/systemctl restart redis-server',
    '/usr/bin/systemctl reload redis-server',
    '/usr/bin/systemctl status redis-server',

    '/usr/bin/systemctl restart fail2ban',
    '/usr/bin/systemctl status fail2ban',
    '/usr/bin/fail2ban-client status',
    '/usr/bin/fail2ban-client status sshd',

    '/usr/bin/systemctl restart frankenphp',
    '/usr/bin/systemctl reload frankenphp',
    '/usr/bin/systemctl status frankenphp',

    '/usr/bin/supervisorctl reread',
    '/usr/bin/supervisorctl update',
    '/usr/bin/supervisorctl restart *',
    '/usr/bin/supervisorctl status *',

    '/usr/bin/systemctl enable *',
    '/usr/bin/systemctl disable *',

    // ─────────────────────────────────────────────────────────────────────
    // Package Management (unchanged - necessary for provisioning)
    // ─────────────────────────────────────────────────────────────────────
    '/usr/bin/apt-get update',
    '/usr/bin/apt-get install *',
    '/usr/bin/add-apt-repository *',
    '/usr/bin/gpg --dearmor -o /usr/share/keyrings/*',

    // ─────────────────────────────────────────────────────────────────────
    // Firewall (unchanged - already scoped to ufw)
    // ─────────────────────────────────────────────────────────────────────
    '/usr/sbin/ufw *',

    // ─────────────────────────────────────────────────────────────────────
    // File Operations - RESTRICTED PATHS ONLY
    // ─────────────────────────────────────────────────────────────────────

    // Caddy configuration
    '/bin/mv /tmp/Caddyfile /etc/caddy/Caddyfile',
    '/bin/mv /tmp/caddy-* /etc/caddy/',
    '/usr/bin/tee /etc/caddy/Caddyfile',
    '/usr/bin/touch /var/log/caddy/access.log',
    '/usr/bin/touch /var/log/caddy/error.log',

    // Fail2ban configuration
    '/bin/mv /tmp/jail.local /etc/fail2ban/jail.local',
    '/bin/mv /tmp/fail2ban-* /etc/fail2ban/',
    '/usr/bin/tee /etc/fail2ban/jail.local',

    // Supervisor configuration
    '/usr/bin/tee /etc/supervisor/conf.d/*.conf',

    // Systemd service files
    '/usr/bin/tee /etc/systemd/system/frankenphp.service',
    '/usr/bin/systemctl daemon-reload',

    // PHP-FPM configuration
    '/usr/bin/tee /etc/php/*/fpm/pool.d/*.conf',
    '/bin/sed -i * /etc/php/*/fpm/php.ini',

    // Deploy path ownership (scoped to /home/deployer)
    '/bin/chown -R deployer:deployer /home/deployer/*',
    '/bin/chmod -R * /home/deployer/*',
    '/bin/mkdir -p /home/deployer/*',

    // Composer/Node binaries (specific targets only)
    '/bin/mv /tmp/composer /usr/local/bin/composer',

    // ─────────────────────────────────────────────────────────────────────
    // Read-only operations (safe)
    // ─────────────────────────────────────────────────────────────────────
    '/bin/cat /etc/os-release',
    '/bin/cat /etc/php/*/fpm/php.ini',
    '/bin/cat /etc/caddy/Caddyfile',
    '/usr/bin/find /etc/php -name php.ini',
]);
```

#### Migration Notes

Existing deployments may need to regenerate sudoers:
```bash
./deploy/dep provision:bootstrap prod
```

---

### 2. Security: Input Validation for Shell-Interpolated Values

**File**: `src/helpers.php` (new function)
**Priority**: High
**Risk if not addressed**: Medium (command injection)

#### Proposed Implementation

```php
/**
 * Validate and sanitize values that will be interpolated into shell commands.
 *
 * @param string $value The value to validate
 * @param string $context Description for error messages
 * @param string $pattern Regex pattern for valid values
 * @throws \InvalidArgumentException If validation fails
 */
function validateShellInput(string $value, string $context, string $pattern = '/^[a-zA-Z0-9._-]+$/'): string
{
    if (!preg_match($pattern, $value)) {
        throw new \InvalidArgumentException(
            "Invalid {$context}: '{$value}'. Must match pattern: {$pattern}"
        );
    }
    return $value;
}

/**
 * Validate domain name format.
 */
function validateDomain(string $domain): string
{
    // Allow: example.com, sub.example.com, localhost, example.test
    $pattern = '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/';
    return validateShellInput($domain, 'domain', $pattern);
}

/**
 * Validate database name format.
 */
function validateDbName(string $name): string
{
    // PostgreSQL/MySQL identifiers: alphanumeric + underscore, start with letter
    $pattern = '/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/';
    return validateShellInput($name, 'database name', $pattern);
}

/**
 * Validate username format.
 */
function validateUsername(string $username): string
{
    // Unix username: alphanumeric + underscore, 1-32 chars
    $pattern = '/^[a-z_][a-z0-9_-]{0,31}$/';
    return validateShellInput($username, 'username', $pattern);
}
```

#### Usage in Provisioning

```php
// In caddy.php
task('caddy:configure', function () {
    $domain = validateDomain(get('domain'));
    $deployPath = get('deploy_path');

    // Now safe to use in heredoc/shell
    $caddyfile = <<<CADDY
    {$domain} {
        root * {$deployPath}/current/public
        // ...
    }
    CADDY;
});

// In database.php
task('provision:database', function () {
    $dbName = validateDbName(get('db_name'));
    $dbUser = validateUsername(get('db_username', 'deployer'));

    // Now safe to interpolate
    run("sudo -u postgres createdb {$dbName}");
});
```

---

### 3. Code Quality: Database Type Constants

**File**: `src/constants.php` (new file)
**Priority**: Medium

#### Proposed Implementation

```php
<?php

namespace Deployer;

/**
 * Database connection types supported by deployer-laravel-stack.
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

    public static function isValid(string $connection): bool
    {
        return in_array($connection, self::ALL, true);
    }

    public static function getPort(string $connection): ?int
    {
        return self::PORTS[$connection] ?? null;
    }

    public static function requiresServer(string $connection): bool
    {
        return $connection !== self::SQLITE;
    }
}

/**
 * Web server types.
 */
final class WebServer
{
    public const FPM = 'fpm';
    public const OCTANE = 'octane';

    public const DEFAULT = self::FPM;
}
```

#### Usage

```php
// Before (magic strings)
if ($dbConnection === 'sqlite') { ... }
if ($dbConnection !== 'pgsql') { ... }

// After (type-safe)
use Deployer\DbConnection;

if ($dbConnection === DbConnection::SQLITE) { ... }
if (!DbConnection::requiresServer($dbConnection)) { ... }

// With validation
if (!DbConnection::isValid($dbConnection)) {
    throw new \InvalidArgumentException(
        "Invalid db_connection: {$dbConnection}. " .
        "Must be one of: " . implode(', ', DbConnection::ALL)
    );
}
```

---

### 4. Default Database: SQLite

**Files**: `src/recipe.php`, `src/tasks/init.php`, `examples/deploy.php`
**Priority**: Medium

#### Changes to recipe.php

```php
// Before
set('db_connection', 'pgsql');

// After
set('db_connection', DbConnection::DEFAULT); // 'sqlite'
```

#### Changes to init.php (wizard)

```php
// Before
$dbChoice = askChoice('Database engine?', [
    'pgsql' => 'PostgreSQL (recommended)',
    'mysql' => 'MySQL',
    'sqlite' => 'SQLite (simple, no server)',
], 'pgsql');

// After
$dbChoice = askChoice('Database engine?', [
    'sqlite' => 'SQLite (recommended for most projects)',
    'pgsql' => 'PostgreSQL (for larger scale)',
    'mysql' => 'MySQL',
], 'sqlite');
```

#### Changes to examples/deploy.php

```php
// ─────────────────────────────────────────────────────────────────────────────
// Database Configuration
// ─────────────────────────────────────────────────────────────────────────────

// SQLite (default) - zero configuration, perfect for most Laravel apps
set('db_connection', 'sqlite');
// Database file created automatically at: {{deploy_path}}/shared/database/database.sqlite

// PostgreSQL - for high-traffic production apps
// set('db_connection', 'pgsql');
// set('db_name', 'myapp');
// set('db_username', 'deployer');

// MySQL - alternative to PostgreSQL
// set('db_connection', 'mysql');
// set('db_name', 'myapp');
// set('db_username', 'deployer');
```

---

### 5. Code Quality: Split Large Task Files

**Priority**: Low
**Rationale**: Improve maintainability without changing behavior

#### migrate.php (14K) -> Split into:

| New File | Contents | Lines |
|----------|----------|-------|
| `src/tasks/migrate.php` | Migration tasks only | ~200 |
| `src/tasks/backup.php` | Backup/restore tasks | ~300 |

#### env.php (8.2K) -> Split into:

| New File | Contents | Lines |
|----------|----------|-------|
| `src/tasks/env.php` | Environment resolution | ~150 |
| `src/tasks/env-generate.php` | .env file generation | ~200 |

---

### 6. Code Quality: Standardize Retry Logic

**File**: `src/helpers.php`
**Priority**: Low

#### Current State

Retry logic exists in two places:
1. `runWithRetry()` helper function
2. Inline retry loops in `bootstrap.php:117-138`

#### Proposed

Remove inline retry loops and use `runWithRetry()` consistently:

```php
// In bootstrap.php - replace inline retry with helper
if ($sudoPass !== null) {
    info("Updating password for existing user '{$user}'...");
    $escapedPass = escapeshellarg($sudoPass);
    $passHash = run("printf '%s' {$escapedPass} | openssl passwd -stdin -6 2>/dev/null");

    runWithRetry(
        "{$sudo}usermod -p '{$passHash}' {$user}",
        maxAttempts: 3,
        delaySeconds: 2,
        onFailure: fn() => warning("Password update failed, ensure NOPASSWD sudo is configured")
    );
}
```

Enhanced helper:

```php
function runWithRetry(
    string $command,
    int $maxAttempts = 3,
    int $delaySeconds = 2,
    ?callable $onFailure = null
): string {
    $lastException = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return run($command);
        } catch (\Throwable $e) {
            $lastException = $e;
            if ($attempt < $maxAttempts) {
                warning("Attempt {$attempt}/{$maxAttempts} failed: {$e->getMessage()}");
                run("sleep {$delaySeconds}");
            }
        }
    }

    if ($onFailure) {
        $onFailure($lastException);
        return '';
    }

    throw new \RuntimeException(
        "Command failed after {$maxAttempts} attempts: {$lastException->getMessage()}",
        0,
        $lastException
    );
}
```

---

### 7. Documentation: Hook Chain Flowchart

**File**: `docs/DEPLOYMENT_FLOW.md` (new)
**Priority**: Low

```markdown
# Deployment Flow

## Task Execution Order

```
deploy
├── deploy:prepare
│   ├── deploy:info
│   ├── deploy:setup
│   └── deploy:lock
├── deploy:release
├── deploy:update_code
├── deploy:shared
├── deploy:writable
├── deploy:vendors
│   └── [after] artisan:config:fresh
│       └── [after] npm:install
│           └── [after] npm:build
│               └── [after] db:fix-sequences (PostgreSQL only)
│                   └── [after] db:ensure-sqlite (SQLite only)
│                       └── [after] migrate:safe
├── deploy:symlink
│   └── [after] php:reload (FPM) OR octane:reload (Octane)
│       └── [after] verify:deployment
├── deploy:unlock
└── deploy:cleanup
    └── [after] deploy:success
```

## Rollback Flow

```
deploy:failed
├── rollback
│   └── deploy:symlink (to previous release)
└── deploy:unlock
```
```

---

## Implementation Plan

### Phase 1: Security (Week 1)

| Task | File | Est. Lines | Priority |
|------|------|------------|----------|
| Tighten sudo wildcards | `bootstrap.php` | ~80 | High |
| Add input validation helpers | `helpers.php` | ~60 | High |
| Apply validation to provisioning | `caddy.php`, `database.php` | ~20 | High |

### Phase 2: Defaults & Constants (Week 2)

| Task | File | Est. Lines | Priority |
|------|------|------------|----------|
| Create constants file | `constants.php` | ~50 | Medium |
| Change default to SQLite | `recipe.php`, `init.php` | ~10 | Medium |
| Update examples | `examples/deploy.php` | ~30 | Medium |
| Update documentation | `README.md` | ~50 | Medium |

### Phase 3: Code Quality (Week 3-4)

| Task | File | Est. Lines | Priority |
|------|------|------------|----------|
| Split migrate.php | `migrate.php`, `backup.php` | ~500 | Low |
| Split env.php | `env.php`, `env-generate.php` | ~350 | Low |
| Standardize retry logic | `helpers.php`, `bootstrap.php` | ~30 | Low |
| Add deployment flow docs | `DEPLOYMENT_FLOW.md` | ~100 | Low |

### Phase 4: Testing (Ongoing)

| Task | Description |
|------|-------------|
| Add PHPUnit tests | Test helper functions, validation |
| Test sudo changes | Verify restricted commands work |
| Test SQLite default | Full deployment with SQLite |
| Regression testing | PostgreSQL, MySQL still work |

---

## Backward Compatibility

### Breaking Changes

1. **Sudo rules more restrictive** - Existing deployments need `provision:bootstrap` re-run
2. **SQLite now default** - New projects get SQLite; existing projects unchanged

### Non-Breaking Changes

- Constants are additive (strings still work)
- File splits preserve all existing task names
- Validation only applies to new deployments

### Migration Guide

```bash
# After upgrading deployer-laravel-stack:

# 1. Regenerate sudo rules (required for security fixes)
./deploy/dep provision:bootstrap prod

# 2. Verify deployment still works
./deploy/dep deploy prod --dry-run

# 3. If using PostgreSQL/MySQL, explicitly set in deploy.php:
#    set('db_connection', 'pgsql');  # or 'mysql'
```

---

## Success Metrics

| Metric | Before | Target |
|--------|--------|--------|
| Sudo wildcard commands | 12 | 0 |
| Magic string occurrences | ~45 | 0 |
| Files over 500 lines | 2 | 0 |
| PHPUnit test coverage | 0% | 60%+ (helpers) |
| New project setup time | ~15 min | ~5 min (SQLite) |

---

## Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Sudo rules too restrictive | Medium | High | Test extensively; provide `provision:sudo:unrestrict` escape hatch |
| SQLite default confuses PostgreSQL users | Low | Low | Clear docs; wizard still offers choice |
| File splits break imports | Low | Medium | Require all files from recipe.php |
| Validation rejects valid input | Low | Medium | Comprehensive regex patterns; clear error messages |

---

## Appendix: Security Audit Checklist

After implementation, verify:

- [ ] `grep -r "NOPASSWD: \*" /etc/sudoers.d/` returns no results
- [ ] Domain injection test: `set('domain', 'example.com; rm -rf /')` throws validation error
- [ ] DB name injection test: `set('db_name', 'test; DROP TABLE users;--')` throws validation error
- [ ] Secrets not logged: `./deploy/dep deploy prod -vvv` doesn't show passwords
- [ ] SQLite deployment works end-to-end without PostgreSQL installed
