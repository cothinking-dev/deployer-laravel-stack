# Database Configuration Guide

This guide explains how to configure deployer-laravel-stack for PostgreSQL, MySQL, or SQLite databases.

## Quick Start

Set `db_connection` in your `deploy.php` to automatically configure database-specific behavior:

```php
// In deploy.php
set('db_connection', 'pgsql');  // or 'mysql' or 'sqlite'
```

---

## PostgreSQL (Default)

PostgreSQL is the default database and requires minimal configuration.

### Configuration

```php
// deploy.php
set('db_connection', 'pgsql'); // Optional, this is the default
set('db_name', 'myapp');
set('db_username', 'deployer');
// Port is auto-detected: 5432

set('secrets', fn () => requireSecrets(
    required: ['DEPLOYER_DB_PASSWORD', 'DEPLOYER_APP_KEY'],
));
```

### What Happens Automatically

- ✅ Pre-flight check: PostgreSQL service running
- ✅ Sequence fixes: Auto-fixes PostgreSQL sequences after data imports
- ✅ Backups: `pg_dump` with gzip compression
- ✅ Restore: `psql` with gunzip
- ✅ Port: Auto-set to 5432

### Environment Variables Generated

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=myapp
DB_USERNAME=deployer
DB_PASSWORD=********
```

---

## MySQL

MySQL/MariaDB support with auto-detected port and proper configuration.

### Configuration

```php
// deploy.php
set('db_connection', 'mysql');
set('db_name', 'myapp');
set('db_username', 'deployer');
// Port is auto-detected: 3306

set('secrets', fn () => requireSecrets(
    required: ['DEPLOYER_DB_PASSWORD', 'DEPLOYER_APP_KEY'],
));
```

### What Happens Automatically

- ✅ Pre-flight check: Skips PostgreSQL service check
- ✅ Sequence fixes: Skipped (MySQL uses AUTO_INCREMENT)
- ✅ Backups: **Not yet implemented** (coming soon)
- ✅ Restore: **Not yet implemented** (coming soon)
- ✅ Port: Auto-set to 3306

### Environment Variables Generated

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=deployer
DB_PASSWORD=********
```

### Notes

- MySQL backup/restore functionality is planned for future release
- Use `mysqldump` manually for backups until integrated

---

## SQLite

File-based database requiring absolute path configuration.

### Configuration

```php
// deploy.php
set('db_connection', 'sqlite');

// Ensure database directory is shared across releases
add('shared_dirs', ['database']);
add('writable_dirs', ['database']);

// IMPORTANT: Use absolute path with {{deploy_path}}
set('shared_env', [
    'DB_DATABASE' => '{{deploy_path}}/shared/database/database.sqlite',
]);
```

### What Happens Automatically

- ✅ Pre-flight check: Skips PostgreSQL service check
- ✅ Database creation: Automatically creates SQLite file if missing
- ✅ Sequence fixes: Skipped (SQLite uses AUTOINCREMENT)
- ✅ Backups: Simple file copy to backup directory
- ✅ Restore: File copy from backup
- ✅ Port: Not applicable (file-based)
- ✅ File permissions: Automatically set to 664

### Environment Variables Generated

```env
DB_CONNECTION=sqlite
DB_HOST=127.0.0.1
DB_PORT=
DB_DATABASE=/home/deployer/myapp/shared/database/database.sqlite
DB_USERNAME=deployer
DB_PASSWORD=
```

### Important Notes

1. **Absolute path required**: Laravel requires absolute path for SQLite
2. **Use `{{deploy_path}}`**: Deployer will resolve this to actual path
3. **Shared directory**: Database file must be in `shared_dirs` to persist
4. **Auto-creation**: Recipe will create file on first deployment
5. **No credentials needed**: SQLite doesn't use DB_USERNAME or DB_PASSWORD

### Common Mistakes

❌ **Wrong** (relative path):
```php
set('shared_env', [
    'DB_DATABASE' => 'database/database.sqlite', // Will fail!
]);
```

✅ **Correct** (absolute path):
```php
set('shared_env', [
    'DB_DATABASE' => '{{deploy_path}}/shared/database/database.sqlite',
]);
```

---

## Feature Matrix

| Feature | PostgreSQL | MySQL | SQLite |
|---------|------------|-------|--------|
| Auto port detection | ✅ (5432) | ✅ (3306) | N/A |
| Pre-flight service check | ✅ | ⚠️ (planned) | N/A |
| Sequence fixes | ✅ | ⚠️ (not needed) | ⚠️ (not needed) |
| Database backups | ✅ (pg_dump) | ⏳ (planned) | ✅ (file copy) |
| Database restore | ✅ (psql) | ⏳ (planned) | ✅ (file copy) |
| Auto-creation | N/A | N/A | ✅ |
| Backup compression | ✅ (gzip) | ⏳ (planned) | ❌ |
| Credentials required | ✅ | ✅ | ❌ |

**Legend**: ✅ Implemented | ⏳ Planned | ⚠️ Skipped (not applicable) | ❌ Not available

---

## Switching Databases

To switch from one database to another:

### PostgreSQL → SQLite

1. Update `deploy.php`:
```php
// Remove/comment PostgreSQL config
// set('db_name', 'myapp');

// Add SQLite config
set('db_connection', 'sqlite');
add('shared_dirs', ['database']);
add('writable_dirs', ['database']);
set('shared_env', [
    'DB_DATABASE' => '{{deploy_path}}/shared/database/database.sqlite',
]);
```

2. Migrate data (use `php artisan db:seed` or custom migration)

3. Deploy: `dep deploy prod`

### PostgreSQL → MySQL

1. Update `deploy.php`:
```php
set('db_connection', 'mysql');
set('db_name', 'myapp');
set('db_username', 'deployer');
```

2. Create MySQL database on server

3. Migrate data

4. Deploy: `dep deploy prod`

---

## Advanced Configuration

### Custom Port

Override the auto-detected port:

```php
set('db_port', '5433'); // Custom PostgreSQL port
```

### Custom Host

For remote databases:

```php
set('db_host', '192.168.1.100');
```

### Multiple Environments

Different databases per environment:

```php
environment('prod', [
    'db_connection' => 'pgsql',
    'db_name' => 'myapp_production',
]);

environment('staging', [
    'db_connection' => 'sqlite',
    'env' => [
        'DB_DATABASE' => '{{deploy_path}}/shared/database/database.sqlite',
    ],
]);
```

### Disable Backups

For development or when using external backups:

```php
set('migrate_backup_enabled', false);
```

---

## Troubleshooting

### SQLite: "Database file does not exist"

**Cause**: Relative path used instead of absolute path

**Solution**: Use `{{deploy_path}}/shared/database/database.sqlite`

### PostgreSQL: "FATAL: role does not exist"

**Cause**: Database user not created

**Solution**:
```bash
dep provision:user prod
dep provision:database prod
```

### MySQL: Service check fails

**Cause**: MySQL service check not yet implemented

**Solution**: Pre-flight checks will skip MySQL-specific checks (coming soon)

---

## Testing Your Configuration

Test database configuration before deploying:

```bash
# Show current environment variables
dep env:show prod

# Validate environment file
dep env:validate prod

# Run pre-flight checks
dep deploy:preflight prod

# List database backups
dep db:backups prod
```

---

## Best Practices

1. **Always use secrets management** for database passwords
2. **Test backups regularly**: Run `dep db:backup prod` and verify
3. **Use shared_dirs** for SQLite to persist data across releases
4. **Set appropriate permissions** on database files (auto-handled for SQLite)
5. **Monitor disk space** for backups (auto-checked in pre-flight)

---

## Need Help?

- **Issues**: https://github.com/cothinking-dev/deployer-laravel-stack/issues
- **Documentation**: See `examples/deploy.php` for complete examples
- **Laravel Docs**: https://laravel.com/docs/database
