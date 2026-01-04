<?php

return [
    'common' => [
        'FILESYSTEM_DISK' => 'local',
        'MAIL_MAILER' => 'smtp',
        'MAIL_FROM_ADDRESS' => 'hello@example.com',
        'MAIL_FROM_NAME' => '${APP_NAME}',
    ],

    'prod' => [
        'GTM_ID' => 'GTM-XXXXXXX',
    ],

    'staging' => [
        'GTM_ID' => '',
    ],
];
