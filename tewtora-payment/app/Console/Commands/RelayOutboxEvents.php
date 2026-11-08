<?php

namespace App\Console\Commands;

use App\Domains\Payment\Models\OutboxEvent;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes Payment's unpublished outbox_events (PaymentSucceeded,
 * PaymentFailed) to the shared RabbitMQ topic exchange, routed by
 * event_type. Same contract as tewtora-core's outbox:relay: a persistent
 * message, and published_at is set only after the publish call, so a crash
 * in between re-sends next run (at-least-once; consumers are idempotent).
 */
class RelayOutboxEvents extends Command
{
    protected $signature = 'outbox:relay';

    protected $description = 'Publish unpublished Payment outbox events to the RabbitMQ broker.';

    public function handle(): int
    {
        if (! OutboxEvent::whereNull('published_at')->exists()) {
            return self::SUCCESS;
        }

        $connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost'),
        );
        $channel = $connection->channel();
        $exchange = config('rabbitmq.exchange');
        $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);

        $published = 0;

        try {
            OutboxEvent::whereNull('published_at')
                ->orderBy('created_at')
                ->chunkById(100, function ($events) use ($channel, $exchange, &$published) {
                    foreach ($events as $event) {
                        $channel->basic_publish(new AMQPMessage(json_encode([
                            'aggregate_type' => $event->aggregate_type,
                            'aggregate_id' => $event->aggregate_id,
                            'event_type' => $event->event_type,
                            'payload' => $event->payload,
                        ]), [
                            'content_type' => 'application/json',
                            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        ]), $exchange, $event->event_type);

                        $event->update(['published_at' => now()]);
                        $published++;
                    }
                });
        } finally {
            $channel->close();
            $connection->close();
        }

        $this->info("Relayed {$published} outbox event(s).");

        return self::SUCCESS;
    }
}
