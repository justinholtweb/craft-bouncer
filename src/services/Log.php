<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Request as WebRequest;
use DateTime;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\models\Verdict;
use justinholtweb\bouncer\Plugin;
use justinholtweb\bouncer\records\AccessLogRecord;

/**
 * The access log (Pro). Off by default.
 *
 * Writing a row per request is a real cost, so this is opt-in, capped by retention, and refuses
 * to run at all outside Pro. What it buys is the only honest answer to "are these rules working":
 * the refusals nobody complained about.
 */
class Log extends Component
{
    public function isEnabled(): bool
    {
        return Edition::allowsAccessLog(Plugin::getInstance()->isPro())
            && Plugin::getInstance()->getSettings()->logDenials;
    }

    /** Record a verdict, if the settings ask for it. */
    public function recordVerdict(Verdict $verdict, ?ElementInterface $element = null, ?string $uri = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($verdict->allowed && !Plugin::getInstance()->getSettings()->logAllowed) {
            return;
        }

        // An unprotected request is not a decision. Logging every page view of a site that has
        // three rules would fill the table with rows that say nothing.
        if (!$verdict->getIsProtected()) {
            return;
        }

        $this->write(
            $verdict->rule,
            $verdict->allowed ? AccessLogRecord::OUTCOME_ALLOWED : AccessLogRecord::OUTCOME_DENIED,
            $verdict->reason,
            $element,
            $uri,
        );
    }

    public function record(?AccessRule $rule, string $outcome, ?string $reason = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->write($rule, $outcome, $reason);
    }

    private function write(
        ?AccessRule $rule,
        string $outcome,
        ?string $reason = null,
        ?ElementInterface $element = null,
        ?string $uri = null,
    ): void {
        $request = Craft::$app->getRequest();
        $isWeb = $request instanceof WebRequest;

        $record = new AccessLogRecord();
        $record->ruleId = $rule?->id;
        $record->userId = Craft::$app->getUser()->getId();
        $record->elementId = $element?->id;
        $record->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $record->uri = $uri ?? ($isWeb ? $request->getFullPath() : null);
        $record->outcome = $outcome;
        $record->reason = $reason;
        $record->ip = $isWeb ? $request->getUserIP() : null;
        // Truncated rather than validated: a 4 kB user agent is a bad actor's problem, not a
        // reason to lose the row or to throw inside a request that was already being refused.
        $record->userAgent = $isWeb ? mb_substr((string)$request->getUserAgent(), 0, 512) : null;

        $record->save(false);
    }

    public function find(array $criteria = []): Query
    {
        $query = (new Query())
            ->select([
                'l.id', 'l.ruleId', 'l.userId', 'l.elementId', 'l.siteId', 'l.uri',
                'l.outcome', 'l.reason', 'l.ip', 'l.userAgent', 'l.dateCreated',
                'ruleName' => 'r.name',
                'username' => 'u.username',
            ])
            ->from(['l' => '{{%bouncer_log}}'])
            ->leftJoin(['r' => '{{%bouncer_rules}}'], '[[r.id]] = [[l.ruleId]]')
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[l.userId]]')
            ->orderBy(['l.dateCreated' => SORT_DESC]);

        if (!empty($criteria['outcome'])) {
            $query->andWhere(['l.outcome' => $criteria['outcome']]);
        }

        if (!empty($criteria['ruleId'])) {
            $query->andWhere(['l.ruleId' => $criteria['ruleId']]);
        }

        return $query;
    }

    /** @return int Rows removed. */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days < 1) {
            return 0;
        }

        $cutoff = new DateTime("-$days days");

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%bouncer_log}}', ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }

    public function clear(): int
    {
        return Craft::$app->getDb()->createCommand()->delete('{{%bouncer_log}}')->execute();
    }
}
