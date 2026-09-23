<?php

return [
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    // Same exchange tewtora-core's outbox:relay publishes onto — see that
    // project's config/rabbitmq.php for the full rationale.
    'exchange' => env('RABBITMQ_EXCHANGE', 'tewtora.events'),
];
