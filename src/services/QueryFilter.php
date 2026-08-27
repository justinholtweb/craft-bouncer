<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\web\Request as WebRequest;
use justinholtweb\bouncer\behaviors\BouncerQueryBehavior;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\models\RuleTarget;
use justinholtweb\bouncer\Plugin;

/**
 * Enforcement in listings: keeping protected elements out of element queries.
 *
 * Without this the plugin is theatre. The entry 403s, and its title, excerpt, date and URL are
 * still in the index page, the search results, the RSS feed, the sitemap and the GraphQL response
 * — all of which are element queries, which is why one hook covers all of them.
 *
 * The decomposition that makes this cheap: a rule's *target* depends on the element, but a rule's
 * *access* depends only on the visitor. So the access half is evaluated once per rule per request,
 * and only the rules that actually refuse this visitor turn into SQL.
 */
class QueryFilter extends Component
{
    /**
     * Re-entrancy guard.
     *
     * Resolving a condition rule means running an element query, which fires this handler again.
     * Without the flag that is infinite recursion, and it shows up as a stack overflow rather than
     * as anything resembling a query problem.
     */
    private bool $_resolving = false;

    /**
     * Whether Craft is still working out what the request is *for*.
     *
     * Craft routes an element URL by running an element query for that URI — so with filtering on,
     * the protected entry is filtered out of Craft's own lookup and the request becomes a bare 404
     * before the guard ever sees it. The rule's configured response (403, a redirect, a paywall
     * template) never happens, and the plugin looks like it is silently breaking URLs.
     *
     * So filtering stands down for the routing window: from `Application::EVENT_BEFORE_REQUEST`
     * until the first controller action begins. Nothing is lost — the guard refuses the request a
     * moment later, with the response the rule actually asked for.
     */
    private bool $_routing = false;

    /** @var array<string, array|null> Rule UID => element IDs to exclude, or null for "all of the source". */
    private array $_conditionIds = [];

    public function beginRouting(): void
    {
        $this->_routing = true;
    }

    public function endRouting(): void
    {
        $this->_routing = false;
    }

    public function handleBeforePrepare(ElementQuery $query): void
    {
        if ($this->_resolving || $this->_routing || !$this->shouldFilter($query)) {
            return;
        }

        $rules = Plugin::getInstance()->rules->getRulesForElementType($query->elementType);

        if ($rules === []) {
            return;
        }

        $siteId = is_int($query->siteId) ? $query->siteId : Craft::$app->getSites()->getCurrentSite()->id;

        foreach ($rules as $rule) {
            if (!$rule->appliesToSite($siteId)) {
                continue;
            }

            // The whole optimisation: a rule this visitor satisfies costs nothing beyond this
            // line, whatever it targets.
            if (Plugin::getInstance()->access->checkRule($rule)->allowed) {
                continue;
            }

            $this->exclude($query, $rule, $siteId);
        }
    }

    private function shouldFilter(ElementQuery $query): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        /** @var BouncerQueryBehavior|null $behavior */
        $behavior = $query->getBehavior('bouncer');

        if ($behavior?->bouncer === false) {
            return false;
        }

        if (!$settings->filterQueries) {
            // An explicit `.bouncer(true)` still filters — that is what makes it possible to
            // switch the global filter off and opt individual queries back in.
            return $behavior?->bouncer === true;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return $behavior?->bouncer === true;
        }

        if ($request->getIsCpRequest() && !$settings->filterCpQueries) {
            return $behavior?->bouncer === true;
        }

        // Previews and share tokens are Craft showing somebody content they were given a link to.
        // Filtering the queries inside a preview would show the author a page missing half its
        // content and no explanation.
        if ($request->getIsPreview() || $request->getIsLivePreview() || $request->getToken() !== null) {
            return false;
        }

        return true;
    }

    private function exclude(ElementQuery $query, AccessRule $rule, int $siteId): void
    {
        $ids = $this->targetIds($rule, $siteId);

        if ($ids === []) {
            return;
        }

        // Applied to the sub-query rather than the outer one: the outer query selects from the
        // sub-query's results, so a condition added there is evaluated after paging has already
        // been decided — which shows up as short pages rather than as missing rows.
        $target = $query->subQuery ?? $query;

        if ($ids === null) {
            $target->andWhere(['not', ['elements.id' => $this->sourceSubQuery($rule)]]);
            return;
        }

        $target->andWhere(['not', ['elements.id' => $ids]]);
    }

    /**
     * The IDs this rule protects, or null meaning "everything the source sub-query returns".
     *
     * Null is the common and cheap case — a whole section — and is left as SQL so it costs one
     * index scan instead of materialising every entry ID on the site.
     */
    private function targetIds(AccessRule $rule, int $siteId): array|null
    {
        if ($rule->target->condition === null) {
            return null;
        }

        // A condition this edition cannot evaluate widens to the whole source rather than
        // narrowing to nothing — the same fail-closed choice {@see RuleTarget::matchesElement()}
        // makes, and it has to be the same one, or the guard and the filter disagree about which
        // entries are protected.
        if ($rule->target->getCondition() === null || !Plugin::getInstance()->isPro()) {
            return null;
        }

        return $this->conditionIds($rule, $siteId);
    }

    /** A sub-select of every element in the rule's sources. */
    private function sourceSubQuery(AccessRule $rule): Query
    {
        $target = $rule->target;

        $query = match ($target->type) {
            RuleTarget::TYPE_ENTRIES => (new Query())->select(['id'])->from(['{{%entries}}']),
            RuleTarget::TYPE_CATEGORIES => (new Query())->select(['id'])->from(['{{%categories}}']),
            RuleTarget::TYPE_ASSETS => (new Query())->select(['id'])->from(['{{%assets}}']),
            default => null,
        };

        if ($query === null) {
            return (new Query())->select(['id'])->from(['{{%elements}}'])->where('0=1');
        }

        if (!$target->allSources) {
            $sourceIds = $this->sourceIds($rule);

            // An empty source list after resolution means the sections were deleted. Excluding
            // nothing would silently unprotect the rule, so exclude everything it could name
            // instead — which is nothing, because the sources are gone.
            $column = match ($target->type) {
                RuleTarget::TYPE_ENTRIES => 'sectionId',
                RuleTarget::TYPE_CATEGORIES => 'groupId',
                RuleTarget::TYPE_ASSETS => 'volumeId',
                default => 'id',
            };

            $query->andWhere([$column => $sourceIds ?: [0]]);
        }

        if ($target->type === RuleTarget::TYPE_ENTRIES && $target->entryTypeUids !== []) {
            $query->andWhere(['typeId' => $this->entryTypeIds($rule) ?: [0]]);
        }

        return $query;
    }

    /**
     * Resolve a condition rule to element IDs, cached.
     *
     * A condition cannot be turned into a join — it is an arbitrary tree of rules over fields,
     * authors and dates — so the only honest way to filter by one is to ask which elements it
     * matches. Past {@see Settings::$conditionIdCap} that stops being reasonable, and the rule
     * drops out of query filtering with a warning rather than being applied to a truncated list:
     * a half-applied exclusion is a leak that looks like it is working.
     */
    private function conditionIds(AccessRule $rule, int $siteId): array
    {
        $key = $rule->uid . ':' . $siteId;

        if (array_key_exists($key, $this->_conditionIds)) {
            return $this->_conditionIds[$key] ?? [];
        }

        $settings = Plugin::getInstance()->getSettings();
        $cacheKey = 'bouncer.conditionIds.' . md5($key . json_encode($rule->target->getConfig()));
        $cached = $settings->conditionCacheDuration > 0 ? Craft::$app->getCache()->get($cacheKey) : false;

        if (is_array($cached)) {
            return $this->_conditionIds[$key] = $cached;
        }

        $ids = $this->resolveConditionIds($rule, $siteId);

        if (count($ids) > $settings->conditionIdCap) {
            Craft::warning(
                "The “{$rule->handle}” rule matches more than {$settings->conditionIdCap} elements, so it is no longer filtering element queries. Raise Bouncer's condition ID cap, or narrow the rule's sources.",
                Plugin::LOG_CATEGORY,
            );

            return $this->_conditionIds[$key] = [];
        }

        if ($settings->conditionCacheDuration > 0) {
            Craft::$app->getCache()->set($cacheKey, $ids, $settings->conditionCacheDuration);
        }

        return $this->_conditionIds[$key] = $ids;
    }

    private function resolveConditionIds(AccessRule $rule, int $siteId): array
    {
        $elementType = $rule->target->getElementType();
        $condition = $rule->target->getCondition();

        if ($elementType === null || $condition === null) {
            return [];
        }

        $this->_resolving = true;

        try {
            /** @var ElementQuery $query */
            $query = $elementType::find();
            $query->siteId = $siteId;
            // Every status, and disabled elements too: a disabled entry that gets enabled later
            // must not appear in the gap before the cache expires.
            $query->status(null);
            $query->limit(null);

            // The source restriction goes on as SQL even when `allSources` is set, because
            // `allSources` still means "every section", not "every element type".
            $query->andWhere(['elements.id' => $this->sourceSubQuery($rule)]);

            $condition->modifyQuery($query);

            return array_map('intval', $query->ids());
        } finally {
            $this->_resolving = false;
        }
    }

    /** Source UIDs to IDs, for whichever kind of source this rule's target names. */
    private function sourceIds(AccessRule $rule): array
    {
        $uids = $rule->target->sourceUids;

        if ($uids === []) {
            return [];
        }

        $table = match ($rule->target->type) {
            RuleTarget::TYPE_ENTRIES => '{{%sections}}',
            RuleTarget::TYPE_CATEGORIES => '{{%categorygroups}}',
            RuleTarget::TYPE_ASSETS => '{{%volumes}}',
            default => null,
        };

        if ($table === null) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['id'])
            ->from([$table])
            ->where(['uid' => $uids])
            ->column());
    }

    private function entryTypeIds(AccessRule $rule): array
    {
        if ($rule->target->entryTypeUids === []) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['id'])
            ->from(['{{%entrytypes}}'])
            ->where(['uid' => $rule->target->entryTypeUids])
            ->column());
    }
}
