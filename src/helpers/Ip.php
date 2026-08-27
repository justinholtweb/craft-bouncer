<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\helpers;

/**
 * IP address and CIDR matching.
 *
 * Hand-rolled rather than leaning on a library because the only operation needed is "is this
 * address inside this range", and getting it right for both families is a hundred lines of
 * bit-twiddling that no dependency is worth.
 *
 * The whole file works on the packed binary form from `inet_pton()`, which makes v4 and v6 the
 * same problem: compare N leading bits of two byte strings. It also sidesteps the trap that
 * `ip2long()` sets for anybody who tries this with integers on a 32-bit build.
 */
class Ip
{
    public static function isValidAddress(string $ip): bool
    {
        return @inet_pton(trim($ip)) !== false;
    }

    /** A single address or a CIDR range — the two things a rule's IP list may hold. */
    public static function isValidPattern(string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        if (!str_contains($pattern, '/')) {
            return self::isValidAddress($pattern);
        }

        [$subnet, $bits] = explode('/', $pattern, 2);

        if (!self::isValidAddress($subnet) || !ctype_digit($bits)) {
            return false;
        }

        $max = strlen((string)inet_pton(trim($subnet))) * 8;

        return (int)$bits >= 0 && (int)$bits <= $max;
    }

    public static function matches(string $ip, string $pattern): bool
    {
        $ip = trim($ip);
        $pattern = trim($pattern);

        if ($ip === '' || $pattern === '') {
            return false;
        }

        $packedIp = @inet_pton($ip);

        if ($packedIp === false) {
            return false;
        }

        if (!str_contains($pattern, '/')) {
            $packedPattern = @inet_pton($pattern);

            return $packedPattern !== false && hash_equals($packedPattern, $packedIp);
        }

        [$subnet, $bits] = explode('/', $pattern, 2);
        $packedSubnet = @inet_pton(trim($subnet));

        if ($packedSubnet === false || !ctype_digit($bits)) {
            return false;
        }

        // A v4 address is never inside a v6 range and vice versa; the packed lengths differ, and
        // comparing them byte-wise would otherwise match on a prefix that means nothing.
        if (strlen($packedSubnet) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int)$bits;
        $maxBits = strlen($packedSubnet) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($wholeBytes > 0 && !hash_equals(substr($packedSubnet, 0, $wholeBytes), substr($packedIp, 0, $wholeBytes))) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainderBits)) - 1) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedSubnet[$wholeBytes]) & $mask);
    }

    /** @param string[] $patterns */
    public static function matchesAny(string $ip, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($ip, (string)$pattern)) {
                return true;
            }
        }

        return false;
    }
}
