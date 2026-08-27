<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * read in one place and tested without an application.
 *
 * The rule the split follows: **Lite is real access control, not a demo.** A site that wants a
 * members-only section gated behind a user group gets that for nothing, including the listing
 * filtering that stops the titles leaking. Pro is the two things that are genuinely harder —
 * *files*, and *conditions that are not "which group are you in"*.
 *
 * Unlike most edition boundaries in this family, this one cannot downgrade gracefully. A lapsed
 * licence on a store locator shows a free map; a lapsed licence on an access-control plugin would
 * show the members area to the public. So {@see self::deniesUnevaluable()} exists instead: Pro
 * conditions are not evaluated on Lite, and a rule with nothing evaluable left over **denies**.
 */
class Edition
{
    /** Volume targets, the guarded delivery route, signed URLs and the exposure audit. */
    public static function allowsAssetProtection(bool $isPro): bool
    {
        return $isPro;
    }

    /** A shared password that unlocks content for a session without an account. */
    public static function allowsPassword(bool $isPro): bool
    {
        return $isPro;
    }

    /** Embargo until, expire after. */
    public static function allowsDateWindow(bool $isPro): bool
    {
        return $isPro;
    }

    /** IP and CIDR allow/deny lists. */
    public static function allowsIpRules(bool $isPro): bool
    {
        return $isPro;
    }

    /** Craft's element condition builder as a rule target, so rules can key on content. */
    public static function allowsElementCondition(bool $isPro): bool
    {
        return $isPro;
    }

    /** Recording who was refused what. */
    public static function allowsAccessLog(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * The target types this edition may protect.
     *
     * Assets are Pro because gating an asset *element* while its file stays fetchable is worse
     * than not gating it — it looks protected. Lite therefore does not offer the target at all
     * rather than offering the half that does not hold.
     */
    public static function allowedTargetTypes(bool $isPro): array
    {
        $types = [
            RuleTarget::TYPE_ENTRIES,
            RuleTarget::TYPE_CATEGORIES,
            RuleTarget::TYPE_URI,
        ];

        if ($isPro) {
            $types[] = RuleTarget::TYPE_ASSETS;
        }

        return $types;
    }

    public static function allowsTargetType(string $type, bool $isPro): bool
    {
        return in_array($type, self::allowedTargetTypes($isPro), true);
    }

    /**
     * Whether a rule that this edition cannot evaluate should deny everybody.
     *
     * Always true, and a named method rather than a bare `true` so the call sites read as the
     * policy they implement: **access control fails closed.** A rule whose only requirement is a
     * password, on an install that cannot check passwords, is not an open door.
     */
    public static function deniesUnevaluable(): bool
    {
        return true;
    }
}
