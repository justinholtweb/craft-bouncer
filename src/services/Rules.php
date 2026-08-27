<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\models\RuleTarget;
use justinholtweb\bouncer\Plugin;
use justinholtweb\bouncer\records\RuleRecord;
use Throwable;

/**
 * Storing and reading access rules.
 *
 * Project config is the source of truth. `{{%bouncer_rules}}` is a mirror, written only by the
 * config handlers below, so a rule created on a laptop arrives in production with the deploy that
 * carries the section it protects — rather than being re-entered by hand, or forgotten.
 */
class Rules extends Component
{
    public const CONFIG_RULES_KEY = 'bouncer.rules';

    /** @var AccessRule[]|null */
    private ?array $_rules = null;

    /** @return AccessRule[] */
    public function getAllRules(): array
    {
        if ($this->_rules === null) {
            $this->_rules = [];

            $rows = (new Query())
                ->select(['id', 'name', 'handle', 'enabled', 'sortOrder', 'targetType', 'settings', 'uid'])
                ->from(['{{%bouncer_rules}}'])
                ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                ->all();

            foreach ($rows as $row) {
                $this->_rules[] = $this->createRuleFromRow($row);
            }
        }

        return $this->_rules;
    }

    /** @return AccessRule[] */
    public function getEnabledRules(): array
    {
        return array_values(array_filter($this->getAllRules(), static fn(AccessRule $rule) => $rule->enabled));
    }

    /**
     * Enabled rules with an element target of the given type.
     *
     * The hot path — every element query and every element check calls this — so it is filtered
     * from the in-memory list rather than from the database.
     *
     * @return AccessRule[]
     */
    public function getRulesForElementType(string $elementType): array
    {
        return array_values(array_filter(
            $this->getEnabledRules(),
            static function(AccessRule $rule) use ($elementType) {
                $ruleElementType = RuleTarget::elementTypeFor($rule->target->type);

                // `is_a` rather than `===` so a site or plugin that subclasses Entry is still
                // covered by an entries rule. Getting this wrong means the rule silently stops
                // applying the day somebody customises an element type.
                return $ruleElementType !== null && is_a($elementType, $ruleElementType, true);
            },
        ));
    }

    /** @return AccessRule[] */
    public function getRulesForTargetType(string $targetType): array
    {
        return array_values(array_filter(
            $this->getEnabledRules(),
            static fn(AccessRule $rule) => $rule->target->type === $targetType,
        ));
    }

    public function getRuleById(?int $id): ?AccessRule
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllRules() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    public function getRuleByHandle(string $handle): ?AccessRule
    {
        foreach ($this->getAllRules() as $rule) {
            if ($rule->handle === $handle) {
                return $rule;
            }
        }

        return null;
    }

    public function getRuleByUid(string $uid): ?AccessRule
    {
        foreach ($this->getAllRules() as $rule) {
            if ($rule->uid === $uid) {
                return $rule;
            }
        }

        return null;
    }

    /** Rules this edition cannot evaluate, and therefore denies. Drives the CP banner. */
    public function getUnevaluableRules(): array
    {
        $isPro = Plugin::getInstance()->isPro();

        return array_values(array_filter(
            $this->getEnabledRules(),
            static fn(AccessRule $rule) => !$rule->isEvaluableBy($isPro),
        ));
    }

    public function saveRule(AccessRule $rule, bool $runValidation = true): bool
    {
        $isNew = $rule->id === null;

        if ($isNew) {
            $rule->uid = StringHelper::UUID();
        } elseif ($rule->uid === null) {
            $rule->uid = Db::uidById('{{%bouncer_rules}}', $rule->id);
        }

        if ($isNew && $rule->sortOrder === 0) {
            $rule->sortOrder = count($this->getAllRules()) + 1;
        }

        if ($runValidation && !$rule->validate()) {
            Craft::info('Access rule not saved due to validation error.', Plugin::LOG_CATEGORY);
            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_RULES_KEY . '.' . $rule->uid,
            $rule->getConfig(),
            "Save the “{$rule->handle}” access rule",
        );

        if ($isNew) {
            $rule->id = Db::idByUid('{{%bouncer_rules}}', $rule->uid);
        }

        $this->_rules = null;

        return true;
    }

    public function reorderRules(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $index => $uid) {
            $config = $projectConfig->get(self::CONFIG_RULES_KEY . '.' . $uid);

            if ($config === null) {
                continue;
            }

            $config['sortOrder'] = $index + 1;
            $projectConfig->set(self::CONFIG_RULES_KEY . '.' . $uid, $config, 'Reorder access rules');
        }

        $this->_rules = null;

        return true;
    }

    public function deleteRuleById(int $id): bool
    {
        $rule = $this->getRuleById($id);

        return $rule !== null && $this->deleteRule($rule);
    }

    public function deleteRule(AccessRule $rule): bool
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_RULES_KEY . '.' . $rule->uid,
            "Delete the “{$rule->handle}” access rule",
        );

        // Project config coalesces an add and a remove inside one request, so the remove handler
        // may never fire — see `[[craft-plugin-gotchas]]`. Tearing the row down here as well keeps
        // both paths correct, and both are idempotent.
        $this->deleteRuleRecord($rule->uid);

        $this->_rules = null;

        return true;
    }

    // Project config handlers
    // ---------------------------------------------------------------------------------------

    public function handleChangedRule(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        // Sites are referenced by UID in a rule's `siteUids`, so they have to exist before the
        // mirror row is written or a fresh `project-config/apply` orders them arbitrarily.
        ProjectConfigHelper::ensureAllSitesProcessed();

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record = RuleRecord::findOne(['uid' => $uid]) ?? new RuleRecord();

            $record->uid = $uid;
            $record->name = (string)($data['name'] ?? '');
            $record->handle = (string)($data['handle'] ?? '');
            $record->enabled = (bool)($data['enabled'] ?? true);
            $record->sortOrder = (int)($data['sortOrder'] ?? 0);
            $record->targetType = (string)($data['target']['type'] ?? RuleTarget::TYPE_ENTRIES);
            $record->settings = Json::encode($data);

            $record->save(false);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->_rules = null;
    }

    public function handleDeletedRule(ConfigEvent $event): void
    {
        $this->deleteRuleRecord($event->tokenMatches[0]);
        $this->_rules = null;
    }

    private function deleteRuleRecord(?string $uid): void
    {
        if ($uid === null) {
            return;
        }

        $record = RuleRecord::findOne(['uid' => $uid]);

        $record?->delete();
    }

    public function rebuildProjectConfig(): array
    {
        $config = [];

        foreach ($this->getAllRules() as $rule) {
            $config[$rule->uid] = $rule->getConfig();
        }

        return $config;
    }

    private function createRuleFromRow(array $row): AccessRule
    {
        $settings = $row['settings'] ? Json::decodeIfJson($row['settings']) : [];
        $settings = is_array($settings) ? $settings : [];

        return new AccessRule($settings + [
            'id' => (int)$row['id'],
            'uid' => $row['uid'],
            'name' => $row['name'],
            'handle' => $row['handle'],
            'enabled' => (bool)$row['enabled'],
            'sortOrder' => (int)$row['sortOrder'],
        ]);
    }
}
