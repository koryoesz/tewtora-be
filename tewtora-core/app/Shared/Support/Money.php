<?php

namespace App\Shared\Support;

use NumberFormatter;

/**
 * Formats stored money (amount_minor + currency_code) for people to read.
 * Only ever called at the view/email layer (CLAUDE.md "Money"). Never
 * stored, never sent to the API (clients format raw minor units themselves).
 */
final class Money
{
    public static function format(int $amountMinor, string $currencyCode): string
    {
        $formatter = new NumberFormatter('en_NG', NumberFormatter::CURRENCY);
        $digits = $amountMinor % 100 === 0 ? 0 : 2;
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

        return $formatter->formatCurrency($amountMinor / 100, $currencyCode);
    }
}
