<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\ActAsRequest;
use App\Domains\Auth\Http\Resources\SupportAccountResource;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Services\AccountSearchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAccountController
{
    public function __construct(
        private readonly AccountSearchService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SupportAccountResource::collection($this->service->search((string) $request->query('q')));
    }

    public function show(Account $account): SupportAccountResource
    {
        return new SupportAccountResource($this->service->view($account));
    }

    public function actAs(ActAsRequest $request, Account $account)
    {
        $token = $this->service->actAs($account, $request->validated('reason'));

        return response()->json(['token' => $token->plainTextToken]);
    }
}
