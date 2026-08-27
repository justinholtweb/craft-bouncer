<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\models;

use craft\base\Model;

/**
 * The answer to "may this visitor have this thing".
 *
 * Every enforcement point in Bouncer asks {@see \justinholtweb\bouncer\services\Access::check()}
 * and acts on one of these. It carries more than a boolean because the callers need different
 * parts of the same answer: the guard needs the *response* to emit, the CP tester needs the
 * *reason*, the password gate needs to know a password would have helped, and the log wants the
 * rule that did it.
 */
class Verdict extends Model
{
    public const REASON_ALLOWED = 'allowed';
    public const REASON_UNPROTECTED = 'unprotected';
    public const REASON_LOGIN = 'login';
    public const REASON_GROUP = 'group';
    public const REASON_PERMISSION = 'permission';
    public const REASON_PASSWORD = 'password';
    public const REASON_NOT_YET = 'notYet';
    public const REASON_EXPIRED = 'expired';
    public const REASON_IP = 'ip';
    public const REASON_UNEVALUABLE = 'unevaluable';

    public bool $allowed = true;

    /** Why. One of the REASON_* constants. */
    public string $reason = self::REASON_UNPROTECTED;

    /** The rule that denied, or null when nothing did. */
    public ?AccessRule $rule = null;

    /**
     * Every rule that targets the subject, denying or not.
     *
     * The guard only needs the first denial, but the CP tester shows the whole picture — "this
     * entry is covered by three rules and you fail the second" is the answer somebody debugging
     * actually wants.
     *
     * @var AccessRule[]
     */
    public array $matchedRules = [];

    /**
     * True when a password would unlock this, so the gate can offer the form.
     *
     * Distinct from `reason === REASON_PASSWORD`: a rule can deny on group membership *and* have a
     * password, and offering the form there would be a lie.
     */
    public bool $passwordWouldUnlock = false;

    public static function allow(array $matchedRules = []): self
    {
        return new self([
            'allowed' => true,
            'reason' => $matchedRules === [] ? self::REASON_UNPROTECTED : self::REASON_ALLOWED,
            'matchedRules' => $matchedRules,
        ]);
    }

    public static function deny(AccessRule $rule, string $reason, array $matchedRules = []): self
    {
        return new self([
            'allowed' => false,
            'reason' => $reason,
            'rule' => $rule,
            'matchedRules' => $matchedRules ?: [$rule],
            'passwordWouldUnlock' => $reason === self::REASON_PASSWORD,
        ]);
    }

    /** Whether the subject is covered by any rule at all — protected content, allowed or not. */
    public function getIsProtected(): bool
    {
        return $this->matchedRules !== [];
    }

    /** The response to emit. Null when allowed. */
    public function getResponse(): ?RuleResponse
    {
        return $this->allowed ? null : $this->rule?->response;
    }
}
