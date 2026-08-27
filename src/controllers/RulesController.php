<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\controllers;

use Craft;
use craft\elements\conditions\ElementConditionInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\models\RuleAccess;
use justinholtweb\bouncer\models\RuleResponse;
use justinholtweb\bouncer\models\RuleTarget;
use justinholtweb\bouncer\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The rules screen.
 *
 * Editing an access rule is an administrative act — it changes who can read the site — so the
 * whole controller is admin-only rather than sitting behind a plugin permission somebody might
 * hand out casually.
 */
class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('bouncer/rules/_index', [
            'rules' => Plugin::getInstance()->rules->getAllRules(),
            'unevaluable' => Plugin::getInstance()->rules->getUnevaluableRules(),
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }

    public function actionEdit(?int $ruleId = null, ?AccessRule $rule = null): Response
    {
        $plugin = Plugin::getInstance();
        $isPro = $plugin->isPro();

        if ($rule === null) {
            $rule = $ruleId !== null ? $plugin->rules->getRuleById($ruleId) : new AccessRule();

            if ($rule === null) {
                throw new NotFoundHttpException('Rule not found');
            }
        }

        return $this->renderTemplate('bouncer/rules/_edit', [
            'rule' => $rule,
            'isNew' => $rule->id === null,
            'isPro' => $isPro,
            'targetTypeOptions' => $this->targetTypeOptions($isPro),
            'sourceOptions' => $this->sourceOptions(),
            'entryTypeOptions' => $this->entryTypeOptions(),
            'userGroupOptions' => $this->userGroupOptions(),
            'permissionOptions' => $this->permissionOptions(),
            'siteOptions' => $this->siteOptions(),
            'responseTypeOptions' => RuleResponse::typeOptions(),
            'condition' => $this->conditionFor($rule),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $isPro = $plugin->isPro();

        $ruleId = $request->getBodyParam('ruleId');
        $rule = $ruleId ? $plugin->rules->getRuleById((int)$ruleId) : new AccessRule();

        if ($rule === null) {
            throw new NotFoundHttpException('Rule not found');
        }

        $rule->name = (string)$request->getBodyParam('name', $rule->name);
        $rule->handle = (string)$request->getBodyParam('handle', $rule->handle);
        $rule->enabled = (bool)$request->getBodyParam('enabled', true);
        $rule->siteUids = array_values((array)$request->getBodyParam('siteUids', []));

        $this->populateTarget($rule, $isPro);
        $this->populateAccess($rule, $isPro);
        $this->populateResponse($rule);

        if (!$plugin->rules->saveRule($rule)) {
            Craft::$app->getSession()->setError(Craft::t('bouncer', 'Couldn’t save the rule.'));

            Craft::$app->getUrlManager()->setRouteParams(['rule' => $rule]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('bouncer', 'Rule saved.'));

        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');

        return $this->asSuccess(data: ['deleted' => Plugin::getInstance()->rules->deleteRuleById($id)]);
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decode(Craft::$app->getRequest()->getRequiredBodyParam('ids'));
        $uids = [];

        foreach ($ids as $id) {
            $rule = Plugin::getInstance()->rules->getRuleById((int)$id);

            if ($rule !== null) {
                $uids[] = $rule->uid;
            }
        }

        Plugin::getInstance()->rules->reorderRules($uids);

        return $this->asSuccess();
    }

    /**
     * "Would this user get in?"
     *
     * The single most useful thing on the screen. Access rules are the kind of configuration
     * nobody is confident about until they have watched it answer, and the alternative to this is
     * logging out and trying it in a private window.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getBodyParam('elementId');
        $userId = $request->getBodyParam('userId');
        $uri = (string)$request->getBodyParam('uri', '');

        $user = $userId ? Craft::$app->getUsers()->getUserById((int)$userId) : null;
        $access = Plugin::getInstance()->access;
        $access->clearCache();

        if ($elementId) {
            $element = Craft::$app->getElements()->getElementById($elementId);

            if ($element === null) {
                throw new NotFoundHttpException('Element not found');
            }

            $verdict = $access->checkElement($element, $user);
        } else {
            $verdict = $access->checkUri($uri, $user);
        }

        $access->clearCache();

        return $this->asJson([
            'allowed' => $verdict->allowed,
            'reason' => $verdict->reason,
            'rule' => $verdict->rule?->name,
            'matched' => array_map(static fn($rule) => $rule->name, $verdict->matchedRules),
        ]);
    }

    // Population
    // ---------------------------------------------------------------------------------------

    private function populateTarget(AccessRule $rule, bool $isPro): void
    {
        $request = Craft::$app->getRequest();
        $posted = (array)$request->getBodyParam('target', []);

        $type = (string)($posted['type'] ?? RuleTarget::TYPE_ENTRIES);

        if (!Edition::allowsTargetType($type, $isPro)) {
            throw new ForbiddenHttpException(Craft::t('bouncer', 'That target type needs Bouncer Pro.'));
        }

        $target = new RuleTarget([
            'type' => $type,
            'allSources' => (bool)($posted['allSources'] ?? false),
            'sourceUids' => array_values(array_filter((array)($posted['sourceUids'] ?? []))),
            'entryTypeUids' => array_values(array_filter((array)($posted['entryTypeUids'] ?? []))),
            'uriPatterns' => $this->normalizeTable($posted['uriPatterns'] ?? [], 'pattern'),
        ]);

        if (Edition::allowsElementCondition($isPro) && !empty($posted['useCondition'])) {
            $conditionsService = Craft::$app->getConditions();
            $conditionConfig = $request->getBodyParam('condition');

            if (is_array($conditionConfig)) {
                $conditionConfig['elementType'] = $target->getElementType();
                /** @var ElementConditionInterface $condition */
                $condition = $conditionsService->createCondition($conditionConfig);
                $target->setCondition($condition);
            }
        }

        $rule->target = $target;
    }

    private function populateAccess(AccessRule $rule, bool $isPro): void
    {
        $request = Craft::$app->getRequest();
        $posted = (array)$request->getBodyParam('access', []);
        $existing = $rule->access;

        $access = new RuleAccess([
            'requireLogin' => (bool)($posted['requireLogin'] ?? false),
            'userGroupUids' => array_values(array_filter((array)($posted['userGroupUids'] ?? []))),
            'groupMatch' => (string)($posted['groupMatch'] ?? RuleAccess::GROUP_MATCH_ANY),
            'permissions' => array_values(array_filter((array)($posted['permissions'] ?? []))),
        ]);

        if ($isPro) {
            $access->passwordDuration = (int)($posted['passwordDuration'] ?? 0);
            $access->ipMode = (string)($posted['ipMode'] ?? RuleAccess::IP_MODE_OFF);
            $access->ips = $this->normalizeTable($posted['ips'] ?? [], 'ip');
            $access->startDate = $this->dateFromPost($posted['startDate'] ?? null);
            $access->endDate = $this->dateFromPost($posted['endDate'] ?? null);

            // The password field posts plain text and is never rendered back, so an empty box on
            // an existing rule means "leave it alone", not "remove it". Removing it takes the
            // explicit checkbox — otherwise every save of an unrelated field would silently
            // unprotect the content.
            $submitted = (string)($posted['password'] ?? '');

            if (!empty($posted['removePassword'])) {
                $access->passwordHash = null;
            } elseif ($submitted !== '') {
                $access->passwordHash = str_starts_with($submitted, '$') && !str_contains($submitted, ' ')
                    ? $submitted
                    : Craft::$app->getSecurity()->generatePasswordHash($submitted);
            } else {
                $access->passwordHash = $existing->passwordHash;
            }
        } else {
            // Lite must not quietly discard Pro settings a Pro install created — a licence lapse
            // should be reversible by renewing, not by re-entering everything.
            $access->passwordHash = $existing->passwordHash;
            $access->passwordDuration = $existing->passwordDuration;
            $access->startDate = $existing->startDate;
            $access->endDate = $existing->endDate;
            $access->ipMode = $existing->ipMode;
            $access->ips = $existing->ips;
        }

        $rule->access = $access;
    }

    private function populateResponse(AccessRule $rule): void
    {
        $posted = (array)Craft::$app->getRequest()->getBodyParam('response', []);

        $rule->response = new RuleResponse([
            'type' => (string)($posted['type'] ?? RuleResponse::TYPE_LOGIN),
            'redirectUrl' => trim((string)($posted['redirectUrl'] ?? '')) ?: null,
            'template' => trim((string)($posted['template'] ?? '')) ?: null,
            'message' => trim((string)($posted['message'] ?? '')) ?: null,
            'preserveUrl' => (bool)($posted['preserveUrl'] ?? false),
        ]);
    }

    /** Craft's editable table posts rows; everything downstream wants a flat list of strings. */
    private function normalizeTable(mixed $rows, string $column): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            $value = is_array($row) ? ($row[$column] ?? '') : $row;
            $value = trim((string)$value);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function dateFromPost(mixed $value): ?\DateTime
    {
        if (empty($value)) {
            return null;
        }

        return DateTimeHelper::toDateTime($value) ?: null;
    }

    // Options
    // ---------------------------------------------------------------------------------------

    private function targetTypeOptions(bool $isPro): array
    {
        $labels = [
            RuleTarget::TYPE_ENTRIES => Craft::t('bouncer', 'Entries'),
            RuleTarget::TYPE_CATEGORIES => Craft::t('bouncer', 'Categories'),
            RuleTarget::TYPE_ASSETS => Craft::t('bouncer', 'Assets'),
            RuleTarget::TYPE_URI => Craft::t('bouncer', 'URIs'),
        ];

        $options = [];

        foreach ($labels as $value => $label) {
            $allowed = Edition::allowsTargetType($value, $isPro);

            $options[] = [
                'value' => $value,
                'label' => $allowed ? $label : Craft::t('bouncer', '{label} (Pro)', ['label' => $label]),
                'disabled' => !$allowed,
            ];
        }

        return $options;
    }

    /** Sources for every target type at once, so the screen can switch without a round trip. */
    private function sourceOptions(): array
    {
        return [
            RuleTarget::TYPE_ENTRIES => array_map(
                static fn($section) => ['value' => $section->uid, 'label' => $section->name],
                Craft::$app->getEntries()->getAllSections(),
            ),
            RuleTarget::TYPE_CATEGORIES => array_map(
                static fn($group) => ['value' => $group->uid, 'label' => $group->name],
                Craft::$app->getCategories()->getAllGroups(),
            ),
            RuleTarget::TYPE_ASSETS => array_map(
                static fn($volume) => ['value' => $volume->uid, 'label' => $volume->name],
                Craft::$app->getVolumes()->getAllVolumes(),
            ),
        ];
    }

    private function entryTypeOptions(): array
    {
        return array_map(
            static fn($type) => ['value' => $type->uid, 'label' => $type->name],
            Craft::$app->getEntries()->getAllEntryTypes(),
        );
    }

    private function userGroupOptions(): array
    {
        return array_map(
            static fn($group) => ['value' => $group->uid, 'label' => $group->name],
            Craft::$app->getUserGroups()->getAllGroups(),
        );
    }

    private function permissionOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            $this->collectPermissions($group['permissions'] ?? [], (string)($group['heading'] ?? ''), $options);
        }

        ArrayHelper::multisort($options, 'label');

        return $options;
    }

    /**
     * Craft's permission list is a tree — "Edit entries" has "Create entries" nested under it —
     * and a flat loop over the top level silently offers half of them.
     */
    private function collectPermissions(array $permissions, string $heading, array &$options): void
    {
        foreach ($permissions as $name => $permission) {
            $label = $permission['label'] ?? $name;

            $options[] = [
                'value' => strtolower((string)$name),
                'label' => $heading !== '' ? "$heading: $label" : $label,
            ];

            if (!empty($permission['nested'])) {
                $this->collectPermissions($permission['nested'], $heading, $options);
            }
        }
    }

    private function siteOptions(): array
    {
        return array_map(
            static fn($site) => ['value' => $site->uid, 'label' => $site->name],
            Craft::$app->getSites()->getAllSites(),
        );
    }

    private function conditionFor(AccessRule $rule): ?ElementConditionInterface
    {
        $elementType = $rule->target->getElementType();

        if ($elementType === null) {
            return null;
        }

        $condition = $rule->target->getCondition();

        if ($condition === null) {
            /** @var ElementConditionInterface $condition */
            $condition = $elementType::createCondition();
        }

        $condition->mainTag = 'div';
        $condition->name = 'condition';
        $condition->id = 'bouncer-condition';

        return $condition;
    }
}
