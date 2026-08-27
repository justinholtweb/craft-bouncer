<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\twig;

use craft\base\ElementInterface;
use justinholtweb\bouncer\helpers\Teaser;
use justinholtweb\bouncer\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Filters and functions, for the places `craft.bouncer.x()` reads badly.
 *
 * `{{ entry.body|teaser(40) }}` in the middle of a template is worth a filter; everything else
 * lives on the variable.
 */
class Extension extends AbstractExtension
{
    public function getName(): string
    {
        return 'bouncer';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('teaser', [$this, 'teaser']),
            new TwigFilter('bouncer', [$this, 'filterElements']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bouncerCan', [$this, 'can']),
        ];
    }

    public function teaser(mixed $value, int $words = 55, string $suffix = '…'): string
    {
        return Teaser::words((string)$value, $words, $suffix);
    }

    /**
     * `{{ entries|bouncer }}` — drop the ones this visitor may not have.
     *
     * For the cases the query filter cannot reach: an array assembled by hand, an eager-loaded
     * relation, the output of a plugin that returns elements rather than a query.
     *
     * @param iterable<ElementInterface> $elements
     * @return ElementInterface[]
     */
    public function filterElements(mixed $elements): array
    {
        if (!is_iterable($elements)) {
            return [];
        }

        $access = Plugin::getInstance()->access;
        $allowed = [];

        foreach ($elements as $element) {
            if ($element instanceof ElementInterface && $access->checkElement($element)->allowed) {
                $allowed[] = $element;
            }
        }

        return $allowed;
    }

    public function can(mixed $subject): bool
    {
        if ($subject instanceof ElementInterface) {
            return Plugin::getInstance()->access->checkElement($subject)->allowed;
        }

        return Plugin::getInstance()->access->checkUri((string)$subject)->allowed;
    }
}
