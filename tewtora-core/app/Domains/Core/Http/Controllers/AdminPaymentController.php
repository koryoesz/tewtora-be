<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Http\Requests\ListPaymentsRequest;
use App\Domains\Core\Http\Requests\PaymentSummaryRequest;
use App\Domains\Core\Http\Resources\AdminPaymentResource;
use App\Domains\Core\Services\PaymentViewService;

/**
 * /internal/payments — the staff Payments page (tewtora-fe
 * docs/needed-endpoints-admin-payments.md). Admin-only (route group's
 * `admin` middleware). Reads Core's payment_view read model; never calls
 * the Payment service.
 */
class AdminPaymentController
{
    public function __construct(
        private readonly PaymentViewService $payments,
    ) {}

    public function index(ListPaymentsRequest $request)
    {
        $page = $this->payments->search($request->validated());

        return response()->json([
            'data' => AdminPaymentResource::collection($page->items())->resolve($request),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function summary(PaymentSummaryRequest $request)
    {
        return response()->json($this->payments->summary(
            $request->validated('from'),
            $request->validated('to'),
            strtoupper((string) $request->validated('currency_code', 'NGN')),
        ));
    }
}
