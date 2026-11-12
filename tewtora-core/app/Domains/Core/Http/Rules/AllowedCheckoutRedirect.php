<?php

namespace App\Domains\Core\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Where Paystack may send the payer after checkout. The URL comes from the
 * client, so without this it would be an open redirect: a phishing link
 * that starts on a genuine Paystack page and ends on someone else's site.
 *
 * Allowed: https on a host in classes.checkout_redirect_hosts (exact host
 * match, no wildcard subdomains), no credentials, no fragment (Paystack
 * appends ?reference=..., which a fragment would swallow). Plain http only
 * for localhost outside production, for frontend dev servers.
 */
class AllowedCheckoutRedirect implements ValidationRule
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            $fail('The :attribute must be a plain https URL on a Tewtora frontend.');

            return;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $local = in_array($host, self::LOCAL_HOSTS, true) && ! app()->isProduction();

        if (! in_array($host, $this->allowedHosts(), true) && ! $local) {
            $fail('The :attribute must point at a Tewtora frontend.');

            return;
        }

        if ($scheme !== 'https' && ! ($scheme === 'http' && $local)) {
            $fail('The :attribute must use https.');

            return;
        }

        if (isset($parts['port']) && ! $local) {
            $fail('The :attribute must not set a port.');
        }
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $configured = (array) config('classes.checkout_redirect_hosts', []);
        $frontend = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);

        return array_values(array_unique(array_filter(array_map(
            fn ($h) => strtolower(trim((string) $h)),
            [...$configured, $frontend],
        ))));
    }
}
