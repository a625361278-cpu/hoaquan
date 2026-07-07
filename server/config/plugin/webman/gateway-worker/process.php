<?php

use Webman\GatewayWorker\Gateway;
use Webman\GatewayWorker\BusinessWorker;
use Webman\GatewayWorker\Register;

return [
    'gateway' => [
        'handler'     => Gateway::class,
        'listen'      => 'websocket://0.0.0.0:' . (int)app_env('GATEWAY_PORT', 8792),
        'count'       => 2,
        'reloadable'  => false,
        'constructor' => ['config' => [
            'lanIp'           => '127.0.0.1',
            'startPort'       => (int)app_env('GATEWAY_START_PORT', 2500),
            'pingInterval'    => 25,
            'pingNotResponseLimit' => 2,
            'pingData'        => '{"type":"ping"}',
            'registerAddress' => (string)app_env('GATEWAY_REGISTER_ADDRESS', '127.0.0.1:1238'),
            'onConnect'       => function(){},
        ]]
    ],
    'worker' => [
        'handler'     => BusinessWorker::class,
        'count'       => cpu_count()*2,
        'constructor' => ['config' => [
            'eventHandler'    => plugin\webman\gateway\Events::class,
            'name'            => 'ThirdPartyScriptBusinessWorker',
            'registerAddress' => (string)app_env('GATEWAY_REGISTER_ADDRESS', '127.0.0.1:1238'),
        ]]
    ],
    'register' => [
        'handler'     => Register::class,
        'listen'      => 'text://' . (string)app_env('GATEWAY_REGISTER_ADDRESS', '127.0.0.1:1238'),
        'count'       => 1, // Must be 1
        'reloadable'  => false,
        'constructor' => []
    ],
];
