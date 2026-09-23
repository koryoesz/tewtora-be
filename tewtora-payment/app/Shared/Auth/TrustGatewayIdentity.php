<?php

namespace App\Shared\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trusts X-Gateway-Account-Id / X-Gateway-Account-Type / X-Gateway-Teacher-Id
 * headers rather than validating a token itself — see GatewayPrincipal's
 * docblock. This is only safe if Payment's network segment truly is the
 * narrowest of the four (CLAUDE.md) and these headers can only arrive from
 * the gateway, never directly from a client — enforcing that boundary is
 * an infra/network concern, not something this middleware can verify on
 * its own. Flagged: no gateway process actually exists in this codebase
 * yet to set these headers for real.
 */
class TrustGatewayIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->header('X-Gateway-Account-Id');
        $accountType = $request->header('X-Gateway-Account-Type');

        if (! $accountId || ! $accountType) {
            abort(401, 'Missing gateway-forwarded identity.');
        }

        $teacherId = $request->header('X-Gateway-Teacher-Id');

        $request->attributes->set('principal', new GatewayPrincipal(
            (int) $accountId,
            $accountType,
            $teacherId !== null ? (int) $teacherId : null,
        ));

        return $next($request);
    }
}
