<?php
return  [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'      => 'mysql',
            'host'        => app_env('DB_HOST', '127.0.0.1'),
            'port'        => app_env('DB_PORT', '3306'),
            'database'    => app_env('DB_DATABASE', 'gameassist'),
            'username'    => app_env('DB_USERNAME', ''),
            'password'    => app_env('DB_PASSWORD', ''),
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_general_ci',
            'prefix'      => '',
            'strict'      => true,
            'engine'      => null,
        ],
    ],
];
