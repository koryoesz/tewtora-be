<?php

namespace App\Console\Commands;

use App\Domains\Auth\Models\OutboxEvent as AuthOutboxEvent;
use App\Domains\Core\Models\OutboxEvent as CoreOutboxEvent;
use Illuminate\Console\Command;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * CLAUDE.md: "a scheduled Artisan command (outbox:relay), run every few
 * seconds... that reads unpublished rows from each domain's outbox_events
 * table and publishes them to the broker." Publishes to a durable RabbitMQ
 * topic exchange (config/rabbitmq.php), routed by event_type, as a
 * persistent message — unlike the Redis pub/sub this replaced, RabbitMQ
 * holds the message until a bound queue's consumer acknowledges it, so a
 * consumer being briefly offline doesn't silently drop events. Rows are
 * only marked published_at after a confirmed publish, so a crash between
 * the two still leaves the row picked up again next run (at-least-once,
 * per microservices-architecture.md §5).
 */
class RelayOutboxEvents extends Command
{
    protected $signature = 'outbox:relay';

    protected $description = 'Publish unpublished outbox events from every domain to the RabbitMQ broker.';

    public function handle(): int
    {
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
            foreach ([AuthOutboxEvent::class, CoreOutboxEvent::class] as $model) {
                $published += $this->relay($model, $channel, $exchange);
            }
        } finally {
            $channel->close();
            $connection->close();
        }

        $this->info("Relayed {$published} outbox event(s).");

        return self::SUCCESS;
    }

    private function relay(string $model, AMQPChannel $channel, string $exchange): int
    {
        $count = 0;

        $model::query()
            ->whereNull('published_at')
            ->orderBy('created_at')
            ->chunkById(100, function ($events) use ($channel, $exchange, &$count) {
                foreach ($events as $event) {
                    $message = new AMQPMessage(json_encode([
                        'aggregate_type' => $event->aggregate_type,
                        'aggregate_id' => $event->aggregate_id,
                        'event_type' => $event->event_type,
                        'payload' => $event->payload,
                    ]), [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    ]);

                    $channel->basic_publish($message, $exchange, $event->event_type);

                    $event->update(['published_at' => now()]);
                    $count++;
                }
            });

        return $count;
    }
}
