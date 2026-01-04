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

    sudo("-u postgres psql -c \"CREATE USER {$dbUser} WITH PASSWORD '{$dbPass}';\" 2>/dev/null || true");
    sudo("-u postgres psql -c \"ALTER USER {$dbUser} CREATEDB;\" 2>/dev/null || true");
    sudo("-u postgres psql -c \"CREATE DATABASE {$dbName} OWNER {$dbUser};\" 2>/dev/null || true");

    sudo("-u postgres psql -d {$dbName} -c \"GRANT ALL ON SCHEMA public TO {$dbUser};\"");
    sudo("-u postgres psql -d {$dbName} -c \"GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO {$dbUser};\"");
    sudo("-u postgres psql -d {$dbName} -c \"GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO {$dbUser};\"");
    sudo("-u postgres psql -d {$dbName} -c \"ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO {$dbUser};\"");
    sudo("-u postgres psql -d {$dbName} -c \"ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO {$dbUser};\"");

    sudo('systemctl restart postgresql');

    info("PostgreSQL configured with database: {$dbName}");
});

desc('Test database connection');
task('db:check', function () {
    $dbPass = getSecret('db_password');
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');

    info("Testing connection to database: {$dbName}");

    $result = run("PGPASSWORD='{$dbPass}' psql -h 127.0.0.1 -U {$dbUser} -d {$dbName} -c '\\dt' 2>&1");
    writeln($result);

    info('Database connection successful');
});

desc('Reset database user password');
task('db:reset-password', function () {
    $dbPass = getSecret('db_password');
    $dbUser = get('db_username', 'deployer');

    sudo("-u postgres psql -c \"ALTER USER {$dbUser} WITH PASSWORD '{$dbPass}';\"");
    info("Password reset for user: {$dbUser}");
});

desc('Show PostgreSQL status');
task('postgres:status', function () {
    $status = sudo('systemctl status postgresql --no-pager -l');
    writeln($status);
});
