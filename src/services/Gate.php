<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\web\Request as WebRequest;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\Plugin;
use justinholtweb\bouncer\records\AccessLogRecord;

/**
 * The password gate (Pro): unlocking a rule for a visitor with no account.
 *
 * State lives in the session, keyed by rule UID, with an optional expiry. Nothing is written to a
 * cookie that the visitor could forge — the session is the only claim, and it is re-checked on
 * every request rather than being turned into a bearer token.
 *
 * The throttle is the part that makes a shared password defensible at all. A four-word password
 * on a public URL with unlimited attempts is a matter of time; capped per IP *and* per rule, with
 * the counter in the cache rather than the session (a session is trivially reset by dropping the
 * cookie), it is not.
 */
class Gate extends Component
{
    private const SESSION_KEY = 'bouncer.unlocked';
    private const CACHE_PREFIX = 'bouncer.attempts.';

    public function isUnlocked(AccessRule $rule): bool
    {
        $unlocked = $this->unlockedRules();

        // `array_key_exists`, not `isset`: a null value means "unlocked for this session", and
        // `isset()` is false for null — so an unlock with no expiry, which is the default, would
        // read back as no unlock at all. The session would be right and every page would still
        // refuse.
        if (!array_key_exists($rule->uid, $unlocked)) {
            return false;
        }

        $expiry = $unlocked[$rule->uid];

        if ($expiry === null) {
            return true;
        }

        if (DateTimeHelper::currentTimeStamp() < $expiry) {
            return true;
        }

        $this->lock($rule);

        return false;
    }

    /**
     * Try a password against a rule.
     *
     * Returns false both for a wrong password and for a throttled visitor, and deliberately does
     * not distinguish them to the caller's return value — the message is set separately, so a
     * controller can say "too many attempts" without this method growing a second meaning.
     */
    public function attempt(AccessRule $rule, string $password): bool
    {
        if (!$rule->access->getHasPassword() || !Plugin::getInstance()->isPro()) {
            return false;
        }

        if ($this->isThrottled($rule)) {
            return false;
        }

        if (!$rule->access->checkPassword($password)) {
            $this->recordAttempt($rule);
            return false;
        }

        $this->unlock($rule);
        $this->clearAttempts($rule);

        Plugin::getInstance()->log->record($rule, AccessLogRecord::OUTCOME_UNLOCKED);

        return true;
    }

    public function unlock(AccessRule $rule): void
    {
        $duration = $rule->access->passwordDuration;
        $unlocked = $this->unlockedRules();
        $unlocked[$rule->uid] = $duration > 0 ? DateTimeHelper::currentTimeStamp() + $duration : null;

        Craft::$app->getSession()->set(self::SESSION_KEY, $unlocked);

        // The verdict cache was populated before the unlock, so anything already asked in this
        // request would still be told "no". Nothing renders between here and the redirect today,
        // but a caller that unlocks and then renders would otherwise get a stale refusal.
        Plugin::getInstance()->access->clearCache();
    }

    public function lock(AccessRule $rule): void
    {
        $unlocked = $this->unlockedRules();
        unset($unlocked[$rule->uid]);

        Craft::$app->getSession()->set(self::SESSION_KEY, $unlocked);
        Plugin::getInstance()->access->clearCache();
    }

    public function lockAll(): void
    {
        Craft::$app->getSession()->remove(self::SESSION_KEY);
        Plugin::getInstance()->access->clearCache();
    }

    public function isThrottled(AccessRule $rule): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->passwordAttempts <= 0) {
            return false;
        }

        return $this->attemptCount($rule) >= $settings->passwordAttempts;
    }

    public function attemptCount(AccessRule $rule): int
    {
        return (int)Craft::$app->getCache()->get($this->throttleKey($rule));
    }

    private function recordAttempt(AccessRule $rule): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $key = $this->throttleKey($rule);
        $count = (int)Craft::$app->getCache()->get($key);

        // Re-setting the whole value resets the window on each failed attempt, which is the
        // intended behaviour: somebody grinding continuously never gets a fresh allowance.
        Craft::$app->getCache()->set($key, $count + 1, $settings->passwordAttemptWindow);
    }

    private function clearAttempts(AccessRule $rule): void
    {
        Craft::$app->getCache()->delete($this->throttleKey($rule));
    }

    private function throttleKey(AccessRule $rule): string
    {
        $request = Craft::$app->getRequest();
        $ip = $request instanceof WebRequest ? ($request->getUserIP() ?? 'unknown') : 'console';

        return self::CACHE_PREFIX . md5($rule->uid . '|' . $ip);
    }

    /** @return array<string, int|null> Rule UID => expiry timestamp, or null for "this session". */
    private function unlockedRules(): array
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return [];
        }

        $unlocked = Craft::$app->getSession()->get(self::SESSION_KEY);

        return is_array($unlocked) ? $unlocked : [];
    }
}
