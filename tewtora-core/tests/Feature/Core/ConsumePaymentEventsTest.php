<?php

namespace Tests\Feature\Core;

use App\Console\Commands\ConsumePaymentEvents;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Services\ClassEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Tests\Concerns\SeedsAuthGraph;
use Tests\Concerns\SeedsClasses;
use Tests\TestCase;

/** payments:consume's message handling: ack what it processed or can never process, nack what may succeed later. */
class ConsumePaymentEventsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAuthGraph;
    use SeedsClasses;

    private function message(array|string $body): AMQPMessage
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->andReturn(is_string($body) ? $body : json_encode($body));

        return $message;
    }

    public function test_a_payment_succeeded_message_confirms_the_enrolment_and_is_acked(): void
    {
        Mail::fake();
        $class = $this->makeClass();
        $enrollment = $this->makeEnrollment($class, ['status' => ClassEnrollment::STATUS_PENDING, 'confirmed_at' => null, 'hold_expires_at' => now()->addMinutes(10)]);

        $message = $this->message(['event_type' => 'PaymentSucceeded', 'payload' => [
            'payment_id' => 'pay-1', 'purpose' => 'class_enrollment', 'purpose_ref' => $enrollment->public_id,
            'amount_minor' => $enrollment->amount_minor, 'currency_code' => 'NGN', 'provider_reference' => 'ref-1',
        ]]);
        $message->shouldReceive('ack')->once();
        $message->shouldNotReceive('nack');

        app(ConsumePaymentEvents::class)->handleMessage($message);

        $this->assertSame(ClassEnrollment::STATUS_CONFIRMED, $enrollment->fresh()->status);
    }

    public function test_a_malformed_message_is_acked_so_it_never_redelivers(): void
    {
        $message = $this->message('{not json');
        $message->shouldReceive('ack')->once();
        $message->shouldNotReceive('nack');

        app(ConsumePaymentEvents::class)->handleMessage($message);
    }

    public function test_a_processing_failure_is_nacked_for_redelivery(): void
    {
        $this->mock(ClassEnrollmentService::class)
            ->shouldReceive('confirmPayment')->andThrow(new RuntimeException('database blip'));

        $message = $this->message(['payload' => ['payment_id' => 'p', 'purpose' => 'class_enrollment', 'purpose_ref' => 'e', 'amount_minor' => 1, 'currency_code' => 'NGN']]);
        $message->shouldReceive('nack')->once()->with(true);
        $message->shouldNotReceive('ack');

        app(ConsumePaymentEvents::class)->handleMessage($message);
    }
}
