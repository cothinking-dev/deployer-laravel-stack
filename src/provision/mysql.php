<?php

namespace Deployer;

desc('Install MySQL and create database');
task('provision:mysql', function () {
    $dbPass = getSecret('db_password');
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');

    if (!$dbName) {
        throw new \RuntimeException('db_name option is required for MySQL setup');
    }

    info('Installing MySQL...');

    sudo('apt-get update');
    sudo('apt-get install -y mysql-server mysql-client');
    sudo('systemctl enable mysql');
    sudo('systemctl start mysql');

    // Configure MySQL to listen on localhost only
    $mysqlConf = '/etc/mysql/mysql.conf.d/mysqld.cnf';
    $confExists = run("test -f {$mysqlConf} && echo 'yes' || echo 'no'");

    if (trim($confExists) === 'yes') {
        sudo("sed -i \"s/^#*bind-address.*/bind-address = 127.0.0.1/\" {$mysqlConf}");
        info('MySQL configured to listen on localhost only');
    }

    // Create user with password
    $createUser = "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '%secret%';";
    run("sudo mysql -e \"{$createUser}\" 2>/dev/null || true", secret: $dbPass);

    // Update password if user exists
    $alterUser = "ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '%secret%';";
    run("sudo mysql -e \"{$alterUser}\" 2>/dev/null || true", secret: $dbPass);

    // Create main database
    createMysqlDatabase($dbName, $dbUser);

    // Create additional databases if configured
    $additionalDbs = get('additional_databases', []);
    foreach ($additionalDbs as $additionalDb) {
        createMysqlDatabase($additionalDb, $dbUser);
    }

    sudo('systemctl restart mysql');

    $allDbs = array_merge([$dbName], $additionalDbs);
    info('MySQL configured with database(s): ' . implode(', ', $allDbs));
});

/**
 * Create a MySQL database and grant privileges.
 */
function createMysqlDatabase(string $dbName, string $dbUser): void
{
    run("sudo mysql -e \"CREATE DATABASE IF NOT EXISTS \`{$dbName}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"");
    run("sudo mysql -e \"GRANT ALL PRIVILEGES ON \`{$dbName}\`.* TO '{$dbUser}'@'localhost';\"");
    run("sudo mysql -e \"FLUSH PRIVILEGES;\"");
}

desc('Create an additional MySQL database');
task('mysql:database', function () {
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');

    if (!$dbName) {
        throw new \RuntimeException('db_name option is required');
    }

    info("Creating database: {$dbName}");
    createMysqlDatabase($dbName, $dbUser);
    info("Database created: {$dbName}");
});

desc('Create MySQL user with password');
task('mysql:user', function () {
    $dbPass = getSecret('db_password');
    $dbUser = get('db_username', 'deployer');

    info("Creating MySQL user: {$dbUser}");

    $createUser = "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '%secret%';";
    run("sudo mysql -e \"{$createUser}\"", secret: $dbPass);

    info("MySQL user created: {$dbUser}");
});

desc('List all MySQL databases');
task('mysql:list-dbs', function () {
    $result = run('sudo mysql -e "SHOW DATABASES;"');
    writeln($result);
});

desc('Show MySQL status');
task('mysql:status', function () {
    $status = sudo('systemctl status mysql --no-pager -l');
    writeln($status);

    $version = run('mysql --version');
    info("MySQL version: {$version}");
});

desc('Test MySQL database connection');
task('mysql:check', function () {
    $dbPass = getSecret('db_password');
    $dbName = get('db_name');
    $dbUser = get('db_username', 'deployer');

    info("Testing connection to database: {$dbName}");

    $result = run("mysql -h 127.0.0.1 -u {$dbUser} -p'%secret%' -e 'SHOW TABLES;' {$dbName} 2>&1", secret: $dbPass);
    writeln($result);

    info('MySQL connection successful');
});

desc('Reset MySQL user password');
task('mysql:reset-password', function () {
    $dbPass = getSecret('db_password');
    $dbUser = get('db_username', 'deployer');

    $alterUser = "ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '%secret%';";
    run("sudo mysql -e \"{$alterUser}\"", secret: $dbPass);
    run("sudo mysql -e \"FLUSH PRIVILEGES;\"");

    info("Password reset for user: {$dbUser}");
});
