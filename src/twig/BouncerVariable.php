<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\twig;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\User;
use justinholtweb\bouncer\helpers\Teaser;
use justinholtweb\bouncer\models\Verdict;
use justinholtweb\bouncer\Plugin;

/**
 * `craft.bouncer` — the template API.
 *
 * Deliberately small, and deliberately answering questions rather than exposing services. A
 * template needs four things: may they have this, what would they need, give me a preview, and
 * give me a link somebody without an account can use.
 */
class BouncerVariable
{
    /**
     * `{% if craft.bouncer.can(entry) %}`
     *
     * Takes an element, a URI string, or a rule handle prefixed with `@` — so a page with no
     * element of its own can still ask about the rule that protects the rest of the site.
     */
    public function can(ElementInterface|string $subject, ?User $user = null): bool
    {
        return $this->check($subject, $user)->allowed;
    }

    /** The inverse, because `{% if not craft.bouncer.can(x) %}` reads badly in a template. */
    public function cannot(ElementInterface|string $subject, ?User $user = null): bool
    {
        return !$this->can($subject, $user);
    }

    /** The whole verdict: `.allowed`, `.reason`, `.rule`, `.isProtected`, `.passwordWouldUnlock`. */
    public function check(ElementInterface|string $subject, ?User $user = null): Verdict
    {
        $access = Plugin::getInstance()->access;
        $forUser = $user ?? false;

        if ($subject instanceof ElementInterface) {
            return $access->checkElement($subject, $forUser);
        }

        if (str_starts_with($subject, '@')) {
            $rule = Plugin::getInstance()->rules->getRuleByHandle(substr($subject, 1));

            return $rule === null ? Verdict::allow() : $access->checkRule($rule, $forUser);
        }

        return $access->checkUri($subject, $forUser);
    }

    /** Whether anything protects this at all — true even for a visitor who is allowed through. */
    public function isProtected(ElementInterface|string $subject): bool
    {
        return $this->check($subject)->getIsProtected();
    }

    /** @return \justinholtweb\bouncer\models\AccessRule[] */
    public function rules(): array
    {
        return Plugin::getInstance()->rules->getEnabledRules();
    }

    public function rule(string $handle): ?\justinholtweb\bouncer\models\AccessRule
    {
        return Plugin::getInstance()->rules->getRuleByHandle($handle);
    }

    /** A preview of protected content: `{{ craft.bouncer.teaser(entry.body, 40) }}`. */
    public function teaser(string $html, int $words = 55, string $suffix = '…'): string
    {
        return Teaser::words($html, $words, $suffix);
    }

    /**
     * A signed, expiring link to a protected file (Pro).
     *
     * The deliberate way to share one file with somebody who has no account — as opposed to the
     * accidental way, which is the file having been readable all along.
     */
    public function signedUrl(Asset $asset, ?int $duration = null, mixed $transform = null): string
    {
        return Plugin::getInstance()->assets->signedUrl($asset, $duration, $transform);
    }

    /** Whether this visitor has unlocked a password-gated rule in this session. */
    public function isUnlocked(string $handle): bool
    {
        $rule = Plugin::getInstance()->rules->getRuleByHandle($handle);

        return $rule !== null && Plugin::getInstance()->gate->isUnlocked($rule);
    }
}
