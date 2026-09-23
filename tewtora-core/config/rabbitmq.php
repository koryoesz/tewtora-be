<?php

return [
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    // Topic exchange every domain's outbox:relay publishes onto, routed by
    // event_type (e.g. 'SessionFeedbackFiled'). One exchange for the whole
    // system, not one per event — consumers bind their own queue to the
    // routing keys they care about.
    'exchange' => env('RABBITMQ_EXCHANGE', 'tewtora.events'),
];
