<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\records;

use craft\db\ActiveRecord;

/**
 * The database mirror of a project-config rule.
 *
 * Project config is the source of truth; this table exists so the CP index can sort and page
 * without parsing YAML, and so the handle-uniqueness validator has something to ask. Nothing
 * writes here except the project-config handlers.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property bool $enabled
 * @property int $sortOrder
 * @property string $targetType
 * @property array|string $settings
 * @property string $uid
 */
class RuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%bouncer_rules}}';
    }
}
