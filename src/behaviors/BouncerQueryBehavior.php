<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\behaviors;

use craft\elements\db\ElementQuery;
use yii\base\Behavior;

/**
 * Adds `.bouncer(false)` to every element query.
 *
 * The opt-out has to exist and it has to be obvious. A site will legitimately want to list
 * protected content it is not showing — "12 more articles for subscribers", a teaser grid, an
 * admin-only report — and without a documented way to turn the filter off, whoever needs that
 * will find an undocumented one.
 *
 * @property ElementQuery $owner
 */
class BouncerQueryBehavior extends Behavior
{
    /**
     * Whether Bouncer filters this query.
     *
     * Null means "whatever the settings say", which is not the same as true: a site with query
     * filtering switched off entirely should not have it switched back on by a query that never
     * mentioned Bouncer.
     */
    public ?bool $bouncer = null;

    /** @return ElementQuery */
    public function bouncer(?bool $value = true): ElementQuery
    {
        $this->bouncer = $value;

        return $this->owner;
    }
}
