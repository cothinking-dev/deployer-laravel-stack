<?php

namespace Deployer;

desc('Install Composer globally');
task('provision:composer', function () {
    info('Installing Composer...');

    run('curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php');
    run('php /tmp/composer-setup.php --quiet');
    sudo('mv composer.phar /usr/local/bin/composer');
    run('rm -f /tmp/composer-setup.php');

    $version = run('composer --version');
    info($version);
});
