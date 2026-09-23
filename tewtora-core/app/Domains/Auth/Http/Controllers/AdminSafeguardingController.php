<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\CloseSafeguardingIncidentRequest;
use App\Domains\Auth\Http\Resources\SafeguardingIncidentResource;
use App\Domains\Auth\Models\SafeguardingIncident;
use App\Domains\Auth\Models\Teacher;
use App\Domains\Auth\Services\SafeguardingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminSafeguardingController
{
    public function __construct(
        private readonly SafeguardingService $service,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return SafeguardingIncidentResource::collection($this->service->open());
    }

    public function show(SafeguardingIncident $incident): SafeguardingIncidentResource
    {
        return new SafeguardingIncidentResource($this->service->view($incident));
    }

    public function suspendNewMatches(Teacher $teacher): array
    {
        $this->service->suspendNewMatches($teacher);

        return ['new_matches_suspended_at' => $teacher->new_matches_suspended_at->toIso8601String()];
    }

    public function close(CloseSafeguardingIncidentRequest $request, SafeguardingIncident $incident): SafeguardingIncidentResource
    {
        return new SafeguardingIncidentResource($this->service->close($incident, $request->validated('note')));
    }
}
