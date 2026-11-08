<?php

namespace App\Console\Commands;

use App\Domains\Core\Jobs\ConfirmClassEnrollmentPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Long-running consumer for PaymentSucceeded events that tewtora-payment's
 * outbox:relay publishes onto the shared RabbitMQ exchange — Core's first
 * consumer, same shape as tewtora-payment's outbox:consume. Run it under
 * Supervisor alongside the queue worker.
 *
 * Durable named queue: events published while this is down wait for it.
 * A message is acked only after the job ran; a failure nacks it for
 * redelivery (at-least-once — the job is idempotent).
 */
class ConsumePaymentEvents extends Command
{
    protected $signature = 'payments:consume';

    protected $description = 'Consume PaymentSucceeded events from RabbitMQ and confirm the class enrolments they paid for.';

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
        $queue = 'core.payment_succeeded';

        $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
        $channel->queue_declare($queue, false, true, false, false);
        $channel->queue_bind($queue, $exchange, 'PaymentSucceeded');

        $this->info("Listening on {$exchange} (routing key: PaymentSucceeded)...");

        $channel->basic_qos(0, 1, false);
        $channel->basic_consume($queue, '', false, false, false, false, fn (AMQPMessage $message) => $this->handleMessage($message));

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    public function handleMessage(AMQPMessage $message): void
    {
        $event = json_decode($message->getBody(), true);
        $payload = is_array($event) ? ($event['payload'] ?? null) : null;

        if (! is_array($payload) || ! isset($payload['payment_id'], $payload['purpose'], $payload['purpose_ref'], $payload['amount_minor'], $payload['currency_code'])) {
            Log::channel('core')->warning('Discarding malformed PaymentSucceeded message', ['body' => $message->getBody()]);
            // Malformed will never parse — ack so it doesn't redeliver forever.
            $message->ack();

            return;
        }

        try {
            // RabbitMQ is already the durable queue; no second hop through Laravel's.
            ConfirmClassEnrollmentPayment::dispatchSync($payload);
            $message->ack();
        } catch (Throwable $e) {
            Log::channel('core')->error('Failed to process PaymentSucceeded', ['payment_id' => $payload['payment_id'], 'exception' => $e]);
            $message->nack(true);
        }
    }
}
