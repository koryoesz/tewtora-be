<?php

namespace App\Domains\Core\Services;

use App\Shared\Support\ContactInfoFilter;

/**
 * Authoritative, server-side contact-info stripping — the frontend already
 * strips the same pattern client-side, but that alone is trivially
 * bypassed (a direct API call skips the browser entirely), which defeats
 * the whole point: stopping parents/teachers moving conversations
 * off-platform where nothing is monitored or auditable. This must run on
 * every message regardless of what the client already did, and the raw
 * un-redacted text must never be persisted anywhere (not even for
 * "safeguarding review" — the redacted version is what a reviewer sees
 * too, since the goal is that the contact detail never lands in the
 * database at all).
 *
 * Pattern mirrors the frontend's regex (phone-like digit runs, emails,
 * URLs, wa.me links) — flagged by the frontend itself as a reasonable
 * starting point rather than a complete one; extend this independently of
 * the frontend's copy as gaps are found, since the two don't need to
 * match exactly for either to do its job.
 */
class MessageRedactor
{
    /** @return array{body: string, redacted: bool} */
    public function redact(string $text): array
    {
        $redactedBody = preg_replace(ContactInfoFilter::PATTERN, '[redacted]', $text, -1, $count);

        return [
            'body' => $redactedBody,
            'redacted' => $count > 0,
        ];
    }
}
