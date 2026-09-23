<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Resources\AuditLogEntryResource;
use App\Shared\Logging\Models\AuditLogEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAuditLogController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AuditLogEntry::with('actor')->orderByDesc('occurred_at');

        if ($subjectId = $request->query('subjectId')) {
            $query->where('subject_id', $subjectId);
        }

        return AuditLogEntryResource::collection($query->limit(100)->get());
    }
}
