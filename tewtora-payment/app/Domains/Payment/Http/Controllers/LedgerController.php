<?php

namespace App\Domains\Payment\Http\Controllers;

use App\Domains\Payment\Services\LedgerService;
use App\Shared\Auth\GatewayPrincipal;
use Illuminate\Http\Request;

/**
 * `:teacherId` is the numeric id, not a public_id — Payment has no
 * teacher public_id mapping of its own (unlike Core/Recommendation's
 * account-link read models), so a real gateway would need to translate
 * the public URL segment before forwarding here. Flagged, not solved,
 * same as the candidate-slots gap in tewtora-core.
 */
class LedgerController
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    public function index(Request $request, int $teacherId): array
    {
        $this->assertSelf($request, $teacherId);

        $filters = $request->only(['status']);

        return ['data' => $this->ledger->entriesForTeacher($teacherId, $filters)->all()];
    }

    public function commission(Request $request, int $teacherId): array
    {
        $this->assertSelf($request, $teacherId);

        return $this->ledger->commissionInfo($teacherId);
    }

    private function assertSelf(Request $request, int $teacherId): void
    {
        /** @var GatewayPrincipal $principal */
        $principal = $request->attributes->get('principal');

        $principal->teacherId === $teacherId || abort(403);
    }
}
