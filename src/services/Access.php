<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\web\Request as WebRequest;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\models\RuleTarget;
use justinholtweb\bouncer\models\Verdict;
use justinholtweb\bouncer\Plugin;

/**
 * The evaluator. The one place in Bouncer that decides anything.
 *
 * Every enforcement point — the request guard, the query filter, the file route, the Twig API,
 * the CP tester — asks this and acts on the {@see Verdict}. Nothing re-implements a slice of the
 * logic for its own convenience, because that is how an access-control plugin ends up allowing
 * through one door what it refuses at another.
 *
 * Two properties this has to hold, and both are easy to lose:
 *
 * - **Deterministic for a given (subject, user).** Results are memoized per request, keyed on
 *   both, so a page that renders the same entry three times cannot answer differently each time.
 * - **Fail closed.** Anything unevaluable denies. See {@see Edition}.
 */
class Access extends Component
{
    /** @var array<string, Verdict> */
    private array $_cache = [];

    private ?bool $_isPro = null;

    /**
     * May this user have this element?
     *
     * @param User|null|false $user The user, or `false` for "whoever is logged in right now".
     */
    public function checkElement(ElementInterface $element, User|null|false $user = false): Verdict
    {
        $user = $user === false ? $this->currentUser() : $user;
        $cacheKey = 'e:' . ($element->id ?? spl_object_id($element)) . ':' . ($element->siteId ?? 0) . ':' . ($user->id ?? 0);

        return $this->_cache[$cacheKey] ??= $this->evaluateElement($element, $user);
    }

    /**
     * May this user have this front-end URI?
     *
     * URI rules only — an entry's own URI is checked through {@see self::checkElement()}, because
     * a URI on its own cannot tell you which entry it is or whether a condition matches it.
     */
    public function checkUri(string $uri, User|null|false $user = false, ?int $siteId = null): Verdict
    {
        $user = $user === false ? $this->currentUser() : $user;
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $cacheKey = 'u:' . $uri . ':' . $siteId . ':' . ($user->id ?? 0);

        return $this->_cache[$cacheKey] ??= $this->evaluateUri($uri, $user, $siteId);
    }

    /** Convenience for templates and callers that only want the boolean. */
    public function can(ElementInterface|string $subject, User|null|false $user = false): bool
    {
        return $subject instanceof ElementInterface
            ? $this->checkElement($subject, $user)->allowed
            : $this->checkUri($subject, $user)->allowed;
    }

    /**
     * Check a single named rule, with no target matching at all.
     *
     * This is what makes `{% bouncer 'subscribers' %}` work — gating a slice of a page that has
     * no element of its own behind the same rule that protects the section.
     */
    public function checkRule(AccessRule $rule, User|null|false $user = false): Verdict
    {
        $user = $user === false ? $this->currentUser() : $user;
        $reason = $this->evaluateRule($rule, $user);

        return $reason === null
            ? Verdict::allow([$rule])
            : Verdict::deny($rule, $reason, [$rule]);
    }

    /** Every rule that targets an element, denying or not. Used by the CP tester and the log. */
    public function matchingRules(ElementInterface $element): array
    {
        $isPro = $this->isPro();
        $matched = [];

        foreach (Plugin::getInstance()->rules->getRulesForElementType($element::class) as $rule) {
            if ($rule->matchesElement($element, $isPro)) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }

    public function clearCache(): void
    {
        $this->_cache = [];
    }

    // ---------------------------------------------------------------------------------------

    private function evaluateElement(ElementInterface $element, ?User $user): Verdict
    {
        $rules = Plugin::getInstance()->rules->getRulesForElementType($element::class);

        if ($rules === []) {
            return Verdict::allow();
        }

        $isPro = $this->isPro();
        $matched = [];

        foreach ($rules as $rule) {
            if ($rule->matchesElement($element, $isPro)) {
                $matched[] = $rule;
            }
        }

        return $this->evaluateMatched($matched, $user);
    }

    private function evaluateUri(string $uri, ?User $user, int $siteId): Verdict
    {
        $matched = [];

        foreach (Plugin::getInstance()->rules->getRulesForTargetType(RuleTarget::TYPE_URI) as $rule) {
            if ($rule->matchesUri($uri, $siteId)) {
                $matched[] = $rule;
            }
        }

        return $this->evaluateMatched($matched, $user);
    }

    /**
     * Every matching rule must pass.
     *
     * AND rather than OR, and worth restating where it is implemented: adding a rule can only ever
     * remove access. "Editors *or* subscribers" lives inside one rule's group list; two rules over
     * the same content mean two hurdles, which is what somebody who wrote two rules meant.
     *
     * @param AccessRule[] $matched
     */
    private function evaluateMatched(array $matched, ?User $user): Verdict
    {
        if ($matched === []) {
            return Verdict::allow();
        }

        if ($this->isExempt($user)) {
            return Verdict::allow($matched);
        }

        $firstDenial = null;
        $passwordWouldUnlock = false;

        foreach ($matched as $rule) {
            $reason = $this->evaluateRule($rule, $user);

            if ($reason === null) {
                continue;
            }

            if ($firstDenial === null) {
                $firstDenial = [$rule, $reason];
            }

            if ($reason === Verdict::REASON_PASSWORD) {
                $passwordWouldUnlock = true;
            }
        }

        if ($firstDenial === null) {
            return Verdict::allow($matched);
        }

        [$rule, $reason] = $firstDenial;

        $verdict = Verdict::deny($rule, $reason, $matched);
        // Only true when the *reported* denial is the password one; a rule that also fails on
        // group membership would otherwise offer a form that cannot possibly help.
        $verdict->passwordWouldUnlock = $passwordWouldUnlock && $reason === Verdict::REASON_PASSWORD;

        return $verdict;
    }

    /**
     * One rule against one user. Returns a REASON_* constant, or null when the rule is satisfied.
     *
     * The order is network → time → identity → password, which is both the cheapest-first order
     * and the one that produces the most useful message: an embargoed page says "not yet" rather
     * than "log in", and the password form is only ever offered as the last thing standing.
     */
    private function evaluateRule(AccessRule $rule, ?User $user): ?string
    {
        $isPro = $this->isPro();

        if (!$rule->isEvaluableBy($isPro)) {
            return Verdict::REASON_UNEVALUABLE;
        }

        $access = $rule->access;

        if ($isPro && Edition::allowsIpRules(true) && !$access->checkIp($this->currentIp())) {
            return Verdict::REASON_IP;
        }

        if ($isPro && Edition::allowsDateWindow(true)) {
            $dateReason = $access->checkDateWindow();

            if ($dateReason !== null) {
                return $dateReason;
            }
        }

        $needsUser = $access->requireLogin || $access->userGroupUids !== [] || $access->permissions !== [];

        if ($needsUser && $user === null) {
            return Verdict::REASON_LOGIN;
        }

        if (!$access->checkGroups($user)) {
            return Verdict::REASON_GROUP;
        }

        if (!$access->checkPermissions($user)) {
            return Verdict::REASON_PERMISSION;
        }

        if ($isPro && $access->getHasPassword() && !Plugin::getInstance()->gate->isUnlocked($rule)) {
            return Verdict::REASON_PASSWORD;
        }

        return null;
    }

    /**
     * Who is never refused.
     *
     * Admins by default, because a plugin whose first act on a typo is to lock the installer out
     * of their own site does not get installed twice. CP users only on request — "can log into
     * the control panel" is a far wider group than "may read the members area".
     */
    private function isExempt(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->exemptAdmins && $user->admin) {
            return true;
        }

        if ($settings->exemptCpUsers && $user->can('accessCp')) {
            return true;
        }

        return $user->can(Plugin::PERMISSION_BYPASS);
    }

    private function currentUser(): ?User
    {
        $identity = Craft::$app->getUser()->getIdentity();

        return $identity instanceof User ? $identity : null;
    }

    private function currentIp(): ?string
    {
        $request = Craft::$app->getRequest();

        return $request instanceof WebRequest ? $request->getUserIP() : null;
    }

    private function isPro(): bool
    {
        return $this->_isPro ??= Plugin::getInstance()->isPro();
    }
}
