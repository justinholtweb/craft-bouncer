<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Bouncer's schema: the project-config mirror, and the access log.
 *
 * Deliberately small. Rules are configuration and live in project config; nothing here is a
 * source of truth, so an uninstall takes both tables with it and loses nothing that matters.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%bouncer_log}}');
        $this->dropTableIfExists('{{%bouncer_rules}}');

        return true;
    }

    private function createTables(): void
    {
        $this->createTable('{{%bouncer_rules}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'targetType' => $this->string(32)->notNull(),
            'settings' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%bouncer_log}}', [
            'id' => $this->primaryKey(),
            'ruleId' => $this->integer(),
            'userId' => $this->integer(),
            'elementId' => $this->integer(),
            'siteId' => $this->integer(),
            'uri' => $this->string(1000),
            'outcome' => $this->string(16)->notNull(),
            'reason' => $this->string(32),
            // Long enough for an IPv6 address with a zone index, which is what a reverse proxy
            // occasionally hands over.
            'ip' => $this->string(64),
            'userAgent' => $this->string(512),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, '{{%bouncer_rules}}', ['handle'], true);
        $this->createIndex(null, '{{%bouncer_rules}}', ['targetType', 'enabled']);
        $this->createIndex(null, '{{%bouncer_log}}', ['dateCreated']);
        $this->createIndex(null, '{{%bouncer_log}}', ['outcome', 'dateCreated']);
        $this->createIndex(null, '{{%bouncer_log}}', ['ruleId']);
    }

    private function addForeignKeys(): void
    {
        // Every one of these is SET NULL rather than CASCADE: a log row whose entry was later
        // deleted is still evidence, and losing it because somebody tidied up the content is the
        // opposite of what a log is for.
        $this->addForeignKey(null, '{{%bouncer_log}}', ['ruleId'], '{{%bouncer_rules}}', ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%bouncer_log}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%bouncer_log}}', ['elementId'], Table::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%bouncer_log}}', ['siteId'], Table::SITES, ['id'], 'SET NULL', null);
    }
}
