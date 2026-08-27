<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\helpers;

use craft\helpers\StringHelper;

/**
 * Cutting a preview out of protected content.
 *
 * The point of a teaser is that the visitor sees enough to want the rest and not enough to have
 * it — so the cut has to happen on the *text*, not the markup. Truncating HTML at a byte offset
 * leaves an open `<div>` that eats the rest of the page, and truncating after stripping tags but
 * before decoding entities cuts `&amp` in half.
 */
class Teaser
{
    /**
     * @param string $html The protected content.
     * @param int $words How many words the reader gets.
     * @param string $suffix Appended when anything was cut.
     */
    public static function words(string $html, int $words = 55, string $suffix = '…'): string
    {
        $text = self::toText($html);

        if ($words < 1) {
            return '';
        }

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) <= $words) {
            return $text;
        }

        return implode(' ', array_slice($parts, 0, $words)) . $suffix;
    }

    public static function characters(string $html, int $length = 300, string $suffix = '…'): string
    {
        $text = self::toText($html);

        if (StringHelper::length($text) <= $length) {
            return $text;
        }

        // Cut on a word boundary rather than mid-word; a teaser that ends "subscri…" reads as a
        // bug rather than as an invitation.
        $cut = StringHelper::substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = StringHelper::substr($cut, 0, $lastSpace);
        }

        return rtrim($cut) . $suffix;
    }

    /**
     * Markup to readable text.
     *
     * Block-level tags become a space before stripping, or "one.</p><p>Two" comes out as
     * "one.Two" — the single most visible way to get this wrong.
     */
    public static function toText(string $html): string
    {
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr|\/blockquote)[^>]*>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
