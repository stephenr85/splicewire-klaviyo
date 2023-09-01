<?php

return [
    'accounts' => [
        'whatever_handle' => [
            'api_key' => '...',
            'campaigns' => [
                'filter' => "and(equals(status,'Sent'),equals(messages.channel,'email'))",
                'fragment_silos' => ['Tater Tots > Klaviyo Messages'],
            ]
        ],
    ]
];
