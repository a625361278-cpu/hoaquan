<?php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'type' => 'mysql',
            'hostname' => app_env('DB_HOST', '127.0.0.1'),
            'database' => app_env('DB_DATABASE', 'gameassist'),
            'username' => app_env('DB_USERNAME', ''),
            'password' => app_env('DB_PASSWORD', ''),
            'hostport' => (int)app_env('DB_PORT', 3306),
            'params' => [
                \PDO::ATTR_TIMEOUT => 3,
            ],
            'charset' => 'utf8mb4',
            'prefix' => '',
            'break_reconnect' => true,
            'trigger_sql' => true,
            'bootstrap' =>  ''
        ],
    ],
];
