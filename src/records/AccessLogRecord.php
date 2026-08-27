<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use craft\records\Site;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * One recorded access decision (Pro).
 *
 * Off by default, and worth saying why it can be turned on at all: the question somebody actually
 * has after deploying access rules is "is this working, and who is hitting it" — and the honest
 * answer needs the refusals that *did not* end in a support ticket.
 *
 * @property int $id
 * @property int|null $ruleId
 * @property int|null $userId
 * @property int|null $elementId
 * @property int|null $siteId
 * @property string|null $uri
 * @property string $outcome
 * @property string|null $reason
 * @property string|null $ip
 * @property string|null $userAgent
 */
class AccessLogRecord extends ActiveRecord
{
    public const OUTCOME_ALLOWED = 'allowed';
    public const OUTCOME_DENIED = 'denied';
    public const OUTCOME_UNLOCKED = 'unlocked';

    public static function tableName(): string
    {
        return '{{%bouncer_log}}';
    }

    public function getRule(): ActiveQueryInterface
    {
        return $this->hasOne(RuleRecord::class, ['id' => 'ruleId']);
    }

    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'elementId']);
    }

    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }
}
