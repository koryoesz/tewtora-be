<?php

namespace App\Console\Commands;

use App\Domains\Core\Jobs\ConfirmClassEnrollmentPayment;
use App\Domains\Core\Jobs\RecordClassEnrollmentPaymentFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Long-running consumer for PaymentSucceeded/PaymentFailed events that tewtora-payment's
 * outbox:relay publishes onto the shared RabbitMQ exchange — Core's first
 * consumer, same shape as tewtora-payment's outbox:consume. Run it under
 * Supervisor alongside the queue worker.
 *
 * PaymentSucceeded confirms the class enrolment it paid for;
 * PaymentFailed records the failure on the staff payments view.
 *
 * Durable named queue: events published while this is down wait for it.
 * A message is acked only after the job ran; a failure nacks it for
 * redelivery (at-least-once — the job is idempotent).
 */
class ConsumePaymentEvents extends Command
{
    protected $signature = 'payments:consume';

    protected $description = 'Consume PaymentSucceeded/PaymentFailed events from RabbitMQ: confirm paid class enrolments, record failures.';

    private const ROUTING_KEYS = ['PaymentSucceeded', 'PaymentFailed'];

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
        foreach (self::ROUTING_KEYS as $routingKey) {
            $channel->queue_bind($queue, $exchange, $routingKey);
        }

        $this->info("Listening on {$exchange} (routing keys: ".implode(', ', self::ROUTING_KEYS).')...');

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
        // Messages from before PaymentFailed was bound carry no reason to doubt: default to Succeeded.
        $type = is_array($event) ? ($event['event_type'] ?? 'PaymentSucceeded') : null;

        if (! is_array($payload) || ! in_array($type, self::ROUTING_KEYS, true)
            || ! isset($payload['payment_id'], $payload['purpose'], $payload['purpose_ref'], $payload['amount_minor'], $payload['currency_code'])) {
            Log::channel('core')->warning('Discarding malformed payment event message', ['body' => $message->getBody()]);
            // Malformed will never parse — ack so it doesn't redeliver forever.
            $message->ack();

            return;
        }

        try {
            // RabbitMQ is already the durable queue; no second hop through Laravel's.
            $type === 'PaymentFailed'
                ? RecordClassEnrollmentPaymentFailure::dispatchSync($payload)
                : ConfirmClassEnrollmentPayment::dispatchSync($payload);
            $message->ack();
        } catch (Throwable $e) {
            Log::channel('core')->error("Failed to process {$type}", ['payment_id' => $payload['payment_id'], 'exception' => $e]);
            $message->nack(true);
        }
    }
}
