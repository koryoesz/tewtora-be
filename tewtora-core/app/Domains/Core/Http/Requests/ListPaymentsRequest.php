<?php

namespace App\Domains\Core\Http\Requests;

use App\Domains\Core\Models\PaymentView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /internal/payments — admin-only (route group's `admin` middleware). */
class ListPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::in(PaymentView::STATUSES)],
            'sort' => ['sometimes', Rule::in(['payer', 'date', 'reference', 'amount', 'status'])],
            'dir' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
