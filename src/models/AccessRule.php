<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\helpers\ArrayHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\bouncer\records\RuleRecord;

/**
 * One access rule: a target, a set of requirements, and what a refused visitor gets.
 *
 * Rules live in project config so they deploy with the sections they protect. That is not a
 * detail — an access rule that has to be re-entered by hand in production is an access rule that
 * will one day not be, and the failure mode is a public members area.
 */
class AccessRule extends Model
{
    public ?int $id = null;
    public ?string $uid = null;

    public string $name = '';
    public string $handle = '';
    public bool $enabled = true;
    public int $sortOrder = 0;

    /**
     * Site UIDs this rule applies to. Empty means every site, including ones added later.
     *
     * @var string[]
     */
    public array $siteUids = [];

    public RuleTarget $target;
    public RuleAccess $access;
    public RuleResponse $response;

    public function __construct($config = [])
    {
        $target = ArrayHelper::remove($config, 'target');
        $access = ArrayHelper::remove($config, 'access');
        $response = ArrayHelper::remove($config, 'response');

        $this->target = $target instanceof RuleTarget ? $target : new RuleTarget($target ?: []);
        $this->access = $access instanceof RuleAccess ? $access : new RuleAccess($access ?: []);
        $this->response = $response instanceof RuleResponse ? $response : new RuleResponse($response ?: []);

        parent::__construct($config);
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->handle;
    }

    public function appliesToSite(?int $siteId): bool
    {
        if ($this->siteUids === []) {
            return true;
        }

        if ($siteId === null) {
            return true;
        }

        $site = Craft::$app->getSites()->getSiteById($siteId);

        return $site !== null && in_array($site->uid, $this->siteUids, true);
    }

    /**
     * Whether this rule covers an element.
     *
     * `$isPro` is threaded through rather than read from the plugin so the boundary stays testable
     * and so a single request never asks the licence question more than once.
     */
    public function matchesElement(ElementInterface $element, bool $isPro): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!$this->appliesToSite($element->siteId ?? null)) {
            return false;
        }

        return $this->target->matchesElement($element, $isPro);
    }

    public function matchesUri(string $uri, ?int $siteId = null): bool
    {
        if (!$this->enabled || !$this->appliesToSite($siteId)) {
            return false;
        }

        return $this->target->matchesUri($uri);
    }

    /**
     * Whether this edition can evaluate the rule at all.
     *
     * Two ways to fail: the target type is Pro-only (an asset rule on Lite), or every requirement
     * is Pro-only (a password-only rule on Lite). Both deny — see {@see Edition}.
     */
    public function isEvaluableBy(bool $isPro): bool
    {
        if (!Edition::allowsTargetType($this->target->type, $isPro)) {
            return false;
        }

        return $this->access->getHasEvaluableRequirement($isPro);
    }

    /** The Pro-only features this rule uses, for the CP banner and the audit command. */
    public function proFeaturesUsed(): array
    {
        $used = [];

        if ($this->target->type === RuleTarget::TYPE_ASSETS) {
            $used[] = Craft::t('bouncer', 'asset protection');
        }

        if ($this->target->condition !== null) {
            $used[] = Craft::t('bouncer', 'element conditions');
        }

        if ($this->access->getHasPassword()) {
            $used[] = Craft::t('bouncer', 'password gating');
        }

        if ($this->access->getHasDateWindow()) {
            $used[] = Craft::t('bouncer', 'date windows');
        }

        if ($this->access->getHasIpRules()) {
            $used[] = Craft::t('bouncer', 'IP rules');
        }

        return $used;
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'uid', 'dateCreated', 'dateUpdated', 'title']],
            [['handle'], UniqueValidator::class, 'targetClass' => RuleRecord::class, 'targetAttribute' => ['handle']],
            [['enabled'], 'boolean'],
            [['sortOrder'], 'integer'],
            [['siteUids'], 'safe'],
            [['target', 'access', 'response'], 'validateSubModels'],
            [['access'], 'validateHasRequirement', 'skipOnEmpty' => false],
            [['response'], 'validatePasswordResponse', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Yii will not descend into a nested model on its own, and a rule whose target is invalid but
     * whose own attributes are fine would otherwise save happily and protect nothing.
     */
    public function validateSubModels(string $attribute): void
    {
        /** @var Model $model */
        $model = $this->$attribute;

        if (!$model->validate()) {
            foreach ($model->getErrors() as $subAttribute => $errors) {
                foreach ($errors as $error) {
                    $this->addError("$attribute.$subAttribute", $error);
                }
            }

            $this->addError($attribute, Craft::t('bouncer', 'Fix the errors above.'));
        }
    }

    /**
     * A rule with no requirements is not "open to everybody" — it is a mistake, and one that reads
     * as protection on the index. Yii skips inline validators on empty attributes, so this needs
     * `skipOnEmpty` off even though the attribute is an object and never empty.
     */
    public function validateHasRequirement(): void
    {
        if (!$this->access->getHasAnyRequirement()) {
            $this->addError('access', Craft::t('bouncer', 'A rule needs at least one requirement, or it protects nothing.'));
        }
    }

    public function validatePasswordResponse(): void
    {
        if ($this->response->type === RuleResponse::TYPE_PASSWORD && !$this->access->getHasPassword()) {
            $this->addError('response.type', Craft::t('bouncer', 'This rule has no password to ask for.'));
        }
    }

    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'enabled' => $this->enabled,
            'sortOrder' => $this->sortOrder,
            'siteUids' => array_values(array_filter($this->siteUids)),
            'target' => $this->target->getConfig(),
            'access' => $this->access->getConfig(),
            'response' => $this->response->getConfig(),
        ];
    }
}
