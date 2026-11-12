<?php

namespace Tests\Concerns;

use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Models\TewtoraClassSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/** Fixtures for Tewtora classes. Use with SeedsAuthGraph. */
trait SeedsClasses
{
    /**
     * A published paid class: `$days` class days, one a week at 16:00–17:00
     * Lagos, the first `$firstDayInDays` days from now (negative = running).
     */
    protected function makeClass(array $overrides = [], int $days = 1, int $firstDayInDays = 7): TewtoraClass
    {
        $admin = $overrides['created_by_account_id'] ?? $this->makeAccount(['account_type' => 'admin'])->id;
        $first = CarbonImmutable::now('Africa/Lagos')->addDays($firstDayInDays)->setTime(16, 0);
        $dates = collect(range(0, $days - 1))->map(fn ($i) => $first->addWeeks($i));

        $class = TewtoraClass::create(array_merge([
            'title' => 'Algebra Foundations',
            'price_minor' => 2500000,
            'currency_code' => 'NGN',
            'capacity' => 10,
            'format' => 'small_group',
            'meeting_link' => 'https://meet.example.test/algebra',
            'status' => TewtoraClass::STATUS_PUBLISHED,
            'starts_at' => $dates->first()->utc(),
            'ends_at' => $dates->last()->addHour()->utc(),
            'published_at' => now(),
            'created_by_account_id' => $admin,
        ], $overrides))->refresh();

        foreach ($dates as $i => $start) {
            TewtoraClassSession::create([
                'tewtora_class_id' => $class->id,
                'day_number' => $i + 1,
                'starts_at' => $start->utc(),
                'ends_at' => $start->addHour()->utc(),
            ]);
        }

        return $class;
    }

    protected function makeEnrollment(TewtoraClass $class, array $overrides = []): ClassEnrollment
    {
        $learner = $overrides['learner_profile_id'] ?? $this->makeLearnerProfile()->id;

        return ClassEnrollment::create(array_merge([
            'tewtora_class_id' => $class->id,
            'learner_profile_id' => $learner,
            'payer_account_id' => $overrides['payer_account_id'] ?? 1,
            'status' => ClassEnrollment::STATUS_CONFIRMED,
            'amount_minor' => $class->price_minor,
            'currency_code' => $class->currency_code,
            'confirmed_at' => now(),
        ], $overrides))->refresh();
    }

    /** Points the Payment client at a fake host and answers the internal API. */
    protected function fakePaymentService(array $checkout = [], array $status = []): void
    {
        config(['services.payment.url' => 'http://payment.test', 'services.payment.internal_token' => 'internal-secret']);

        // A fresh fake each time: Http::fake() appends, and earlier stubs would win.
        Http::swap(new Factory);
        Http::fake([
            // A closure, so every checkout gets its own payment_id (they're unique per enrolment).
            'payment.test/api/v1/internal/checkouts' => fn () => Http::response(['data' => array_merge([
                'payment_id' => 'pay-'.uniqid('', true),
                'status' => 'processing',
                'checkout_url' => 'https://checkout.paystack.test/abc123',
                'provider_reference' => 'ref-123',
            ], $checkout)], 201),
            // Like Payment: echoes the payment id it was asked about, and what
            // that payment was for (the enrolment that started it).
            'payment.test/api/v1/internal/checkouts/*' => function (HttpRequest $request) use ($status) {
                $paymentId = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));

                return Http::response(['data' => array_merge([
                    'payment_id' => $paymentId,
                    'purpose' => 'class_enrollment',
                    'purpose_ref' => ClassEnrollment::where('payment_id', $paymentId)->value('public_id'),
                    'status' => 'success',
                    'amount_minor' => 2500000,
                    'currency_code' => 'NGN',
                    'provider_reference' => 'ref-123',
                ], $status)]);
            },
        ]);
    }

    /** The Payment service is down / the provider rejected the call. */
    protected function failPaymentService(int $status = 502): void
    {
        Http::swap(new Factory);
        Http::fake(['payment.test/*' => Http::response(['error' => ['code' => 'payment_provider_error']], $status)]);
    }
}
