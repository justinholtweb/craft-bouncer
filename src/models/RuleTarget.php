<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use justinholtweb\bouncer\models\Edition;

/**
 * *What* a rule protects.
 *
 * Four target types, because there are four genuinely different things a site owner points at:
 * a section, a category group, a volume, and "that part of the site that isn't elements at all".
 * The last one matters more than it looks — a members dashboard rendered from a template route
 * has no element to hang a rule on, and every element-only access plugin leaves it wide open.
 */
class RuleTarget extends Model
{
    public const TYPE_ENTRIES = 'entries';
    public const TYPE_CATEGORIES = 'categories';
    public const TYPE_ASSETS = 'assets';
    public const TYPE_URI = 'uri';

    public string $type = self::TYPE_ENTRIES;

    /**
     * Section / category group / volume UIDs.
     *
     * UIDs rather than IDs because this lives in project config and has to survive a deploy to a
     * database that has never seen this section before.
     *
     * @var string[]
     */
    public array $sourceUids = [];

    /** Protect every source of this type, including ones added later. */
    public bool $allSources = false;

    /**
     * Narrow an entries target to particular entry types. Empty means all of them.
     *
     * @var string[]
     */
    public array $entryTypeUids = [];

    /**
     * URI glob patterns, for the `uri` type.
     *
     * `*` matches within a segment, `**` matches across them, and a pattern ending `/**` also
     * matches the bare prefix — so `members/**` covers `members` and everything under it, which
     * is what everybody means when they type it.
     *
     * @var string[]
     */
    public array $uriPatterns = [];

    /**
     * A serialized Craft element condition (Pro), or null.
     *
     * Stored as the condition's config array rather than an instantiated object because project
     * config has to be plain data — and because instantiating a condition needs field layouts,
     * which are not available at every point a rule gets read.
     */
    public ?array $condition = null;

    private ?ElementConditionInterface $_condition = null;

    public static function elementTypeFor(string $type): ?string
    {
        return match ($type) {
            self::TYPE_ENTRIES => Entry::class,
            self::TYPE_CATEGORIES => Category::class,
            self::TYPE_ASSETS => Asset::class,
            default => null,
        };
    }

    public function getElementType(): ?string
    {
        return self::elementTypeFor($this->type);
    }

    /** Whether this target is about elements at all — `uri` is the one that is not. */
    public function getIsElementTarget(): bool
    {
        return $this->getElementType() !== null;
    }

    public function getCondition(): ?ElementConditionInterface
    {
        if ($this->condition === null || $this->condition === []) {
            return null;
        }

        if ($this->_condition === null) {
            $config = $this->condition;
            $config['elementType'] ??= $this->getElementType();

            /** @var ElementConditionInterface $condition */
            $condition = Craft::$app->getConditions()->createCondition($config);
            $this->_condition = $condition;
        }

        return $this->_condition;
    }

    public function setCondition(ElementConditionInterface|array|null $condition): void
    {
        $this->_condition = null;

        if ($condition instanceof ElementConditionInterface) {
            $this->condition = $condition->getConfig();
            return;
        }

        $this->condition = $condition ?: null;
    }

    /**
     * Whether this target covers an element.
     *
     * The source check is deliberately first and cheap: the condition is the expensive half and
     * most calls fall out before reaching it.
     */
    public function matchesElement(ElementInterface $element, bool $isPro = true): bool
    {
        $elementType = $this->getElementType();

        if ($elementType === null || !($element instanceof $elementType)) {
            return false;
        }

        if (!$this->matchesSource($element)) {
            return false;
        }

        if ($this->condition !== null) {
            // A condition this edition cannot evaluate widens the target to the whole source
            // rather than narrowing it to nothing. Narrowing would unprotect the section the
            // moment a licence lapsed, which is the failure this plugin exists to prevent — so
            // the source match already made above stands, and the rule denies.
            if (!Edition::allowsElementCondition($isPro)) {
                return true;
            }

            $condition = $this->getCondition();

            if ($condition !== null && !$condition->matchElement($element)) {
                return false;
            }
        }

        return true;
    }

    /** The source half of {@see self::matchesElement()}, without the condition. */
    public function matchesSource(ElementInterface $element): bool
    {
        $sourceUid = $this->sourceUidFor($element);

        if ($sourceUid === null) {
            return false;
        }

        if (!$this->allSources && !in_array($sourceUid, $this->sourceUids, true)) {
            return false;
        }

        if (
            $this->type === self::TYPE_ENTRIES &&
            $this->entryTypeUids !== [] &&
            $element instanceof Entry
        ) {
            return in_array($element->getType()->uid, $this->entryTypeUids, true);
        }

        return true;
    }

    private function sourceUidFor(ElementInterface $element): ?string
    {
        return match (true) {
            $element instanceof Entry => $element->getSection()?->uid,
            $element instanceof Category => $element->getGroup()->uid,
            $element instanceof Asset => $element->getVolume()->uid,
            default => null,
        };
    }

    /**
     * Whether a front-end URI is covered.
     *
     * Element targets answer this too, via their elements' own URIs — but that path goes through
     * {@see self::matchesElement()}, because a URI alone cannot tell you which entry it is.
     */
    public function matchesUri(string $uri): bool
    {
        if ($this->type !== self::TYPE_URI) {
            return false;
        }

        $uri = trim($uri, '/');

        foreach ($this->uriPatterns as $pattern) {
            if (self::uriMatchesPattern($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function uriMatchesPattern(string $uri, string $pattern): bool
    {
        $uri = trim($uri, '/');
        $pattern = trim($pattern, '/');

        if ($pattern === '') {
            return false;
        }

        // Craft's own home-page URI is the empty string; `__home__` names it, as it does in
        // routes and sitemaps, because an empty pattern would match by accident.
        if ($pattern === '__home__') {
            return $uri === '';
        }

        if ($pattern === '**') {
            return true;
        }

        // `members/**` is meant to include `members`. Nobody writes the second pattern.
        if (str_ends_with($pattern, '/**') && $uri === substr($pattern, 0, -3)) {
            return true;
        }

        $regex = preg_quote($pattern, '/');
        // Order matters: the two-star token has to be consumed before the single-star one, or
        // `**` is read as two `*`s and stops crossing slashes.
        $regex = str_replace(['\*\*', '\*'], ["\x00", '[^\/]*'], $regex);
        $regex = str_replace("\x00", '.*', $regex);

        return (bool)preg_match('/^' . $regex . '$/', $uri);
    }

    protected function defineRules(): array
    {
        return [
            [['type'], 'required'],
            [['type'], 'in', 'range' => [self::TYPE_ENTRIES, self::TYPE_CATEGORIES, self::TYPE_ASSETS, self::TYPE_URI]],
            [['sourceUids', 'entryTypeUids', 'uriPatterns', 'condition'], 'safe'],
            [['allSources'], 'boolean'],
            [['sourceUids'], 'validateSources', 'skipOnEmpty' => false],
            [['uriPatterns'], 'validateUriPatterns', 'skipOnEmpty' => false],
        ];
    }

    public function validateSources(): void
    {
        if (!$this->getIsElementTarget()) {
            return;
        }

        if (!$this->allSources && $this->sourceUids === []) {
            $this->addError('sourceUids', Craft::t('bouncer', 'Choose at least one source, or protect all of them.'));
        }
    }

    public function validateUriPatterns(): void
    {
        if ($this->type !== self::TYPE_URI) {
            return;
        }

        if ($this->uriPatterns === []) {
            $this->addError('uriPatterns', Craft::t('bouncer', 'Enter at least one URI pattern.'));
        }
    }

    public function getConfig(): array
    {
        return [
            'type' => $this->type,
            'sourceUids' => array_values(array_filter($this->sourceUids)),
            'allSources' => $this->allSources,
            'entryTypeUids' => array_values(array_filter($this->entryTypeUids)),
            'uriPatterns' => array_values(array_filter(array_map(
                static fn($p) => StringHelper::trim((string)$p),
                $this->uriPatterns,
            ))),
            'condition' => $this->condition,
        ];
    }
}
