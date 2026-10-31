<?php

namespace App\Shared\Mail;

/**
 * The email palette, derived from the frontend's brand green (#1BAA6E) so
 * emails look like the app. Shared into every mail view as $theme
 * (AppServiceProvider). Templates never hard-code a hex value, so a brand
 * change is a one-file edit.
 *
 * `brand` is for large surfaces (hero panel, button, progress, logo tile).
 * Small green text on white uses `brandInk` instead: white-on-#1BAA6E is
 * ~3:1 contrast, and #11845A on white is ~4.7:1 (WCAG AA for body text).
 */
final class MailTheme
{
    /** @return array<string, string> */
    public static function colors(): array
    {
        return [
            // Brand
            'brand' => '#1BAA6E',
            'brandLight' => '#2BBF80',   // hero gradient start
            'brandDark' => '#138A57',    // hero gradient end
            'brandInk' => '#11845A',     // small accent text/links on light backgrounds
            'buttonGlow' => 'rgba(27, 170, 110, 0.28)',

            // Brand tints
            'canvas' => '#EDF8F2',       // page background
            'tint' => '#E4F6EE',         // chips, avatar circles
            'soft' => '#F3FAF6',         // step cards, info boxes
            'ring' => '#C5E8D7',         // outline of an inactive step number
            'trackMid' => '#A8DEC4',     // progress: next step
            'track' => '#D8F1E5',        // progress: later steps
            'onBrandMuted' => '#CDF2E0', // eyebrow text on the hero
            'onBrandSoft' => '#E6F8EF',  // subline text on the hero
            'glowA' => '#CDEFDF',
            'glowAClear' => 'rgba(205, 239, 223, 0)',
            'glowB' => '#DDF3E8',
            'glowBClear' => 'rgba(221, 243, 232, 0)',

            // Neutrals
            'white' => '#FFFFFF',
            'text' => '#111827',
            'body' => '#4B5563',
            'mutedStrong' => '#6B7280',
            'muted' => '#8B929A',
            'divider' => '#E8ECEA',
            'field' => '#F4F6F5',
            'fieldBorder' => '#E2E7E4',
            'cardShadow' => 'rgba(16, 70, 45, 0.08)',

            // Warnings ("Wasn't you?"). Deliberately not brand-coloured.
            'danger' => '#B4372A',
            'dangerBg' => '#FFF4F2',
            'dangerBorder' => '#FBDCD6',
        ];
    }
}
