<?php

namespace Deployer;

set('storage_links', []);

desc('Create custom storage symlinks from public to shared directories');
task('storage:link-custom', function () {
    $links = get('storage_links');

    if (empty($links)) {
        return;
    }

    foreach ($links as $publicPath => $sharedPath) {
        $sharedFullPath = "{{deploy_path}}/shared/{$sharedPath}";
        $publicFullPath = "{{release_path}}/public/{$publicPath}";

        run("mkdir -p {$sharedFullPath}");
        run("rm -rf {$publicFullPath}");
        run("ln -s {$sharedFullPath} {$publicFullPath}");

        info("Linked public/{$publicPath} → shared/{$sharedPath}");
    }
});
