<?php

return [
    'default' => env('BROADCAST_DRIVER', 'reverb'),

    'connections' => [

        'reverb' => [
            'driver'   => 'reverb',
            'key'      => env('REVERB_APP_KEY'),
            'secret'   => env('REVERB_APP_SECRET'),
            'app_id'   => env('REVERB_APP_ID'),
            'options'  => [
                'host'   => env('REVERB_HOST'),
                'port'   => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key'    => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'host'    => env('PUSHER_HOST') ?: 'api-' . env('PUSHER_APP_CLUSTER', 'mt1') . '.pusher.com',
                'port'    => env('PUSHER_PORT', 443),
                'scheme'  => env('PUSHER_SCHEME', 'https'),
                'useTLS'  => true,
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
            ],
            'client_options' => [],
        ],

        'log'  => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
    ],
];