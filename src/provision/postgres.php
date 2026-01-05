<?php

namespace Deployer;

desc('Install PostgreSQL and create database');
task('provision:postgres', function () {
    $dbPass = getSecret('db_password');
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');

    if (! $dbName) {
        throw new \RuntimeException('db_name option is required for PostgreSQL setup');
    }

    info('Installing PostgreSQL...');

    sudo('apt-get update');
    sudo('apt-get install -y postgresql postgresql-contrib');
    sudo('systemctl enable postgresql');
    sudo('systemctl start postgresql');

    $pgConf = trim(sudo("find /etc/postgresql -name 'postgresql.conf' 2>/dev/null | head -1"));
    if ($pgConf) {
        sudo("sed -i \"s/^#*listen_addresses.*/listen_addresses = 'localhost'/\" {$pgConf}");
        info('PostgreSQL configured to listen on localhost only');
    }

    run("sudo -u postgres psql -c \"CREATE USER {$dbUser} WITH PASSWORD '%secret%';\" 2>/dev/null || true", secret: $dbPass);
    run('sudo -u postgres psql -c "ALTER USER ' . $dbUser . ' CREATEDB;" 2>/dev/null || true');

    createDatabase($dbName, $dbUser);

    $additionalDbs = get('additional_databases', []);
    foreach ($additionalDbs as $additionalDb) {
        createDatabase($additionalDb, $dbUser);
    }

    sudo('systemctl restart postgresql');

    $allDbs = array_merge([$dbName], $additionalDbs);
    info('PostgreSQL configured with database(s): ' . implode(', ', $allDbs));
});

function createDatabase(string $dbName, string $dbUser): void
{
    run("sudo -u postgres psql -c \"CREATE DATABASE {$dbName} OWNER {$dbUser};\" 2>/dev/null || true");
    run("sudo -u postgres psql -d {$dbName} -c \"GRANT ALL ON SCHEMA public TO {$dbUser};\"");
    run("sudo -u postgres psql -d {$dbName} -c \"GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO {$dbUser};\"");
    run("sudo -u postgres psql -d {$dbName} -c \"GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO {$dbUser};\"");
    run("sudo -u postgres psql -d {$dbName} -c \"ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO {$dbUser};\"");
    run("sudo -u postgres psql -d {$dbName} -c \"ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO {$dbUser};\"");
}

desc('Create an additional database');
task('postgres:create-db', function () {
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');

    if (! $dbName) {
        throw new \RuntimeException('db_name option is required');
    }

    info("Creating database: {$dbName}");
    createDatabase($dbName, $dbUser);
    info("Database created: {$dbName}");
});

desc('List all databases');
task('postgres:list-dbs', function () {
    $result = run('sudo -u postgres psql -l');
    writeln($result);
});

desc('Test database connection');
task('db:check', function () {
    $dbPass = getSecret('db_password');
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');

    info("Testing connection to database: {$dbName}");

    $result = run("PGPASSWORD='%secret%' psql -h 127.0.0.1 -U {$dbUser} -d {$dbName} -c '\\dt' 2>&1", secret: $dbPass);
    writeln($result);

    info('Database connection successful');
});

desc('Reset database user password');
task('db:reset-password', function () {
    $dbPass = getSecret('db_password');
    $dbUser = get('db_username', 'deployer');

    run("sudo -u postgres psql -c \"ALTER USER {$dbUser} WITH PASSWORD '%secret%';\"", secret: $dbPass);
    info("Password reset for user: {$dbUser}");
});

desc('Show PostgreSQL status');
task('postgres:status', function () {
    $status = sudo('systemctl status postgresql --no-pager -l');
    writeln($status);
});

desc('Fix PostgreSQL sequences to prevent duplicate key errors');
task('db:fix-sequences', function () {
    $dbPass = getSecret('db_password');
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');

    info("Fixing PostgreSQL sequences for: {$dbName}");

    // Get all sequences in the database
    $sequences = run("PGPASSWORD='%secret%' psql -h 127.0.0.1 -U {$dbUser} -d {$dbName} -t -c \"SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public';\" 2>/dev/null || true", secret: $dbPass);

    foreach (explode("\n", $sequences) as $sequence) {
        $sequence = trim($sequence);
        if (empty($sequence)) {
            continue;
        }

        // Extract table name from sequence (assumes convention: {table}_id_seq)
        $tableName = preg_replace('/_id_seq$/', '', $sequence);

        // Reset sequence to max id
        $sql = "SELECT setval('{$sequence}', COALESCE((SELECT MAX(id) FROM {$tableName}), 1), true);";
        run("PGPASSWORD='%secret%' psql -h 127.0.0.1 -U {$dbUser} -d {$dbName} -c \"{$sql}\" 2>/dev/null || true", secret: $dbPass);
    }

    info('PostgreSQL sequences fixed');
});
