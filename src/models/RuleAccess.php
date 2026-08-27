<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use DateTime;
use justinholtweb\bouncer\helpers\Ip;

/**
 * *Who* gets through.
 *
 * Every requirement here is opt-in and they are combined with AND. That is the boring answer, and
 * it is the right one: a rule reads top to bottom as a list of things that must all be true, and
 * adding a requirement can only ever tighten it. The one place OR appears is inside the user-group
 * list, where {@see self::$groupMatch} chooses — because "editors or subscribers" is the single
 * case that genuinely needs it and would otherwise force two rules with confusing overlap.
 */
class RuleAccess extends Model
{
    public const GROUP_MATCH_ANY = 'any';
    public const GROUP_MATCH_ALL = 'all';

    public const IP_MODE_OFF = 'off';
    public const IP_MODE_ALLOW = 'allow';
    public const IP_MODE_DENY = 'deny';

    /** Any logged-in user gets through. The simplest useful rule there is. */
    public bool $requireLogin = false;

    /** @var string[] User group UIDs. */
    public array $userGroupUids = [];

    public string $groupMatch = self::GROUP_MATCH_ANY;

    /** @var string[] Craft permission names, lowercased as Craft stores them. */
    public array $permissions = [];

    /**
     * A hashed shared password (Pro), or null.
     *
     * Hashed, not stored, because this ends up in `project.yaml` in a git repository. The CP field
     * takes plain text, hashes on save, and never renders the stored value back — there is nothing
     * to render.
     *
     * A value of the form `$SOME_ENV_VAR` is passed through untouched and compared as plain text
     * at check time, which is how somebody keeps the password out of the repo entirely.
     */
    public ?string $passwordHash = null;

    /** How long a password unlock lasts, in seconds. Zero means the session. */
    public int $passwordDuration = 0;

    public ?DateTime $startDate = null;
    public ?DateTime $endDate = null;

    public string $ipMode = self::IP_MODE_OFF;

    /** @var string[] IPv4/IPv6 addresses or CIDR ranges. */
    public array $ips = [];

    public function __construct($config = [])
    {
        foreach (['startDate', 'endDate'] as $attr) {
            if (isset($config[$attr]) && $config[$attr] !== '' && $config[$attr] !== null) {
                $config[$attr] = DateTimeHelper::toDateTime($config[$attr]) ?: null;
            } else {
                $config[$attr] = null;
            }
        }

        parent::__construct($config);
    }

    public function getHasPassword(): bool
    {
        return $this->passwordHash !== null && $this->passwordHash !== '';
    }

    public function getHasDateWindow(): bool
    {
        return $this->startDate !== null || $this->endDate !== null;
    }

    public function getHasIpRules(): bool
    {
        return $this->ipMode !== self::IP_MODE_OFF && $this->ips !== [];
    }

    /**
     * Whether anything here can be checked by this edition.
     *
     * The load-bearing method for the downgrade policy. A rule whose only requirement is a
     * password, on a Lite install, has nothing evaluable — and denies rather than opening.
     */
    public function getHasEvaluableRequirement(bool $isPro): bool
    {
        if ($this->requireLogin || $this->userGroupUids !== [] || $this->permissions !== []) {
            return true;
        }

        return $isPro && ($this->getHasPassword() || $this->getHasDateWindow() || $this->getHasIpRules());
    }

    /** Whether anything is configured at all, in any edition. An empty rule protects nothing. */
    public function getHasAnyRequirement(): bool
    {
        return $this->getHasEvaluableRequirement(true);
    }

    /**
     * The date window, checked against now.
     *
     * Returns a REASON_* constant when it refuses, null when it is satisfied. A window that has
     * not opened and one that has closed are different answers on purpose — "available from
     * Tuesday" and "this has expired" are not the same message to a reader.
     */
    public function checkDateWindow(?DateTime $now = null): ?string
    {
        if (!$this->getHasDateWindow()) {
            return null;
        }

        $now ??= DateTimeHelper::now();

        if ($this->startDate !== null && $now < $this->startDate) {
            return Verdict::REASON_NOT_YET;
        }

        if ($this->endDate !== null && $now >= $this->endDate) {
            return Verdict::REASON_EXPIRED;
        }

        return null;
    }

    /** True when the address is acceptable to the IP rules. An unparseable address never is. */
    public function checkIp(?string $ip): bool
    {
        if (!$this->getHasIpRules()) {
            return true;
        }

        $matches = $ip !== null && Ip::matchesAny($ip, $this->ips);

        return $this->ipMode === self::IP_MODE_ALLOW ? $matches : !$matches;
    }

    /** Whether a user satisfies the group requirement. */
    public function checkGroups(?User $user): bool
    {
        if ($this->userGroupUids === []) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $userGroupUids = array_map(static fn($group) => $group->uid, $user->getGroups());

        if ($this->groupMatch === self::GROUP_MATCH_ALL) {
            return array_diff($this->userGroupUids, $userGroupUids) === [];
        }

        return array_intersect($this->userGroupUids, $userGroupUids) !== [];
    }

    public function checkPermissions(?User $user): bool
    {
        if ($this->permissions === []) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        foreach ($this->permissions as $permission) {
            if (!$user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a submitted password is the right one.
     *
     * Handles both storage shapes: an env-var reference compares plain text (constant-time), and
     * anything else is a hash.
     */
    public function checkPassword(string $submitted): bool
    {
        if (!$this->getHasPassword()) {
            return false;
        }

        $stored = $this->passwordHash;

        if (str_starts_with($stored, '$') && !str_starts_with($stored, '$2y$') && !str_starts_with($stored, '$2a$')) {
            $expected = App::parseEnv($stored);

            // An env var that is not set parses back to the literal `$NAME`. Comparing against
            // that would let anybody in who typed the variable name, so refuse instead.
            if ($expected === $stored || $expected === null || $expected === '') {
                return false;
            }

            return hash_equals((string)$expected, $submitted);
        }

        return Craft::$app->getSecurity()->validatePassword($submitted, $stored);
    }

    protected function defineRules(): array
    {
        return [
            [['requireLogin'], 'boolean'],
            [['userGroupUids', 'permissions', 'ips', 'passwordHash'], 'safe'],
            [['groupMatch'], 'in', 'range' => [self::GROUP_MATCH_ANY, self::GROUP_MATCH_ALL]],
            [['ipMode'], 'in', 'range' => [self::IP_MODE_OFF, self::IP_MODE_ALLOW, self::IP_MODE_DENY]],
            [['passwordDuration'], 'integer', 'min' => 0],
            [['startDate', 'endDate'], 'safe'],
            [['endDate'], 'validateDateWindow'],
            [['ips'], 'validateIps', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Yii's CompareValidator stringifies its operands, and two DateTimes stringified is a fatal —
     * see `[[craft-plugin-gotchas]]`. So the comparison is done here by hand.
     */
    public function validateDateWindow(): void
    {
        if ($this->startDate !== null && $this->endDate !== null && $this->endDate <= $this->startDate) {
            $this->addError('endDate', Craft::t('bouncer', 'The end date must be after the start date.'));
        }
    }

    public function validateIps(): void
    {
        if ($this->ipMode === self::IP_MODE_OFF) {
            return;
        }

        if ($this->ips === []) {
            $this->addError('ips', Craft::t('bouncer', 'Enter at least one IP address or range.'));
            return;
        }

        foreach ($this->ips as $ip) {
            if (!Ip::isValidPattern($ip)) {
                $this->addError('ips', Craft::t('bouncer', '“{ip}” is not a valid IP address or CIDR range.', ['ip' => $ip]));
            }
        }
    }

    public function getConfig(): array
    {
        return [
            'requireLogin' => $this->requireLogin,
            'userGroupUids' => array_values(array_filter($this->userGroupUids)),
            'groupMatch' => $this->groupMatch,
            'permissions' => array_values(array_filter($this->permissions)),
            'passwordHash' => $this->passwordHash ?: null,
            'passwordDuration' => $this->passwordDuration,
            'startDate' => $this->startDate ? DateTimeHelper::toIso8601($this->startDate) : null,
            'endDate' => $this->endDate ? DateTimeHelper::toIso8601($this->endDate) : null,
            'ipMode' => $this->ipMode,
            'ips' => array_values(array_filter(array_map('trim', $this->ips))),
        ];
    }
}
