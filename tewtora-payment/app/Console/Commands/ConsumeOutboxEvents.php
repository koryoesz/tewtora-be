<?php

namespace App\Console\Commands;

use App\Domains\Payment\Jobs\ReleasePaymentForFiledFeedback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Long-running consumer for events tewtora-core's outbox:relay publishes
 * onto the shared RabbitMQ topic exchange (config/rabbitmq.php). Only
 * SessionFeedbackFiled is bound in this pass — PaymentSucceeded/
 * PaymentFailed are published BY this service (microservices-
 * architecture.md §5), not consumed by it.
 *
 * Declares its own durable, named queue bound to the exchange — this is
 * RabbitMQ's actual advantage over the Redis pub/sub this replaced: the
 * queue holds messages published while this consumer isn't running, so
 * restarting it (a deploy, a crash) doesn't silently lose events the way
 * pub/sub would have. Messages are only ack'd after the job is
 * successfully dispatched; a failure leaves the message for redelivery
 * (at-least-once, same idempotency discipline as everywhere else).
 */
class ConsumeOutboxEvents extends Command
{
    protected $signature = 'outbox:consume';

    protected $description = 'Consume cross-service outbox events from RabbitMQ and dispatch the matching job.';

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
        $queue = 'payment.session_feedback_filed';

        $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
        $channel->queue_declare($queue, false, true, false, false);
        $channel->queue_bind($queue, $exchange, 'SessionFeedbackFiled');

        $this->info("Listening on {$exchange} (routing key: SessionFeedbackFiled)...");

        $channel->basic_qos(0, 1, false);
        $channel->basic_consume($queue, '', false, false, false, false, function (AMQPMessage $message) {
            $this->handleMessage($message);
        });

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    private function handleMessage(AMQPMessage $message): void
    {
        $event = json_decode($message->getBody(), true);

        if (! is_array($event) || ! isset($event['payload']['session_id'], $event['payload']['feedback_id'])) {
            Log::channel('payment')->warning('Discarding malformed SessionFeedbackFiled message', [
                'body' => $message->getBody(),
            ]);

            // Malformed, not just momentarily unprocessable — acknowledge
            // so it doesn't redeliver forever; requeueing would never fix
            // a payload that will never parse.
            $message->ack();

            return;
        }

        try {
            // dispatchSync, not dispatch: RabbitMQ is already the durable
            // queue here — re-queuing onto this app's own Redis queue
            // would be a second, redundant hop.
            ReleasePaymentForFiledFeedback::dispatchSync(
                (int) $event['payload']['session_id'],
                (int) $event['payload']['feedback_id'],
            );

            $message->ack();
        } catch (Throwable $e) {
            Log::channel('payment')->error('Failed to process SessionFeedbackFiled', [
                'body' => $message->getBody(),
                'exception' => $e->getMessage(),
            ]);

            // Leaves the message unacked for redelivery — a transient
            // failure (e.g. a DB blip) should retry, not silently drop a
            // payment release.
            $message->nack(true);
        }
    }
}
