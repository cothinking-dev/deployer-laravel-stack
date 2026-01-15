<?php

namespace Deployer;

desc('Provision database based on db_connection setting');
task('provision:database', function () {
    $dbConnection = get('db_connection', 'pgsql');

    info("Provisioning database: {$dbConnection}");

    switch ($dbConnection) {
        case 'pgsql':
            invoke('provision:postgres');
            break;

        case 'mysql':
            invoke('provision:mysql');
            break;

        case 'sqlite':
            invoke('provision:sqlite');
            break;

        default:
            throw new \RuntimeException("Unsupported database connection: {$dbConnection}");
    }
});

desc('Show database status based on db_connection setting');
task('db:status', function () {
    $dbConnection = get('db_connection', 'pgsql');

    switch ($dbConnection) {
        case 'pgsql':
            invoke('postgres:status');
            break;

        case 'mysql':
            invoke('mysql:status');
            break;

        case 'sqlite':
            invoke('sqlite:status');
            break;

        default:
            throw new \RuntimeException("Unsupported database connection: {$dbConnection}");
    }
});
