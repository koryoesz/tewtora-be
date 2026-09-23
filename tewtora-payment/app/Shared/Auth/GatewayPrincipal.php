<?php

namespace App\Shared\Auth;

/**
 * Payment has no Sanctum/Account model of its own — accounts live in
 * Auth's database. In a real deployment, the API gateway validates the
 * caller's Sanctum token against Auth once and forwards a trusted
 * identity downstream (microservices-architecture.md §3: "Client → any
 * service: Sync REST/gRPC via API gateway"); Payment trusts that header
 * pair rather than re-authenticating. See TrustGatewayIdentity.
 *
 * `accountId` is the numeric id, not the public_id — this is an internal
 * gateway-to-service hop, never externally exposed, and it's exactly what
 * payments.payer_account_id / payment_line_items.teacher_id (both
 * denormalized numeric ids, per their migrations) need to compare against
 * for authorization. teacherId is only set when the caller is a teacher —
 * Payment doesn't own the account_id -> teacher_id mapping, so the
 * gateway must resolve and forward it (mirroring how Recommendation/Core
 * both keep an account_id -> teacher_id read model rather than querying
 * Auth directly).
 */
class GatewayPrincipal
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountType,
        public readonly ?int $teacherId = null,
    ) {}
}
