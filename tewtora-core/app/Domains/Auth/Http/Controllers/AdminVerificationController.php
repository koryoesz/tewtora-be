<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\DecideVerificationRequest;
use App\Domains\Auth\Http\Resources\VerificationApplicationResource;
use App\Domains\Auth\Models\Teacher;
use App\Domains\Auth\Services\VerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminVerificationController
{
    public function __construct(
        private readonly VerificationService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return VerificationApplicationResource::collection(
            $this->service->applications($request->query('state'))
        );
    }

    public function show(Teacher $teacher): VerificationApplicationResource
    {
        return new VerificationApplicationResource($this->service->viewApplication($teacher));
    }

    public function decide(DecideVerificationRequest $request, Teacher $teacher): VerificationApplicationResource
    {
        $teacher = $this->service->decide($teacher, $request->validated('decision'), $request->validated('note'));

        return new VerificationApplicationResource($teacher->load('verificationChecks'));
    }
}
