<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\models;

use craft\base\Model;
use craft\models\Volume;

/**
 * What an audit found out about one volume Bouncer is protecting.
 *
 * The severity scale is short on purpose. "Critical" means the protected file can be fetched
 * right now by anybody who knows the URL, and nothing else in the plugin changes that. Everything
 * else is advice.
 */
class VolumeExposure extends Model
{
    public const SEVERITY_OK = 'ok';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public ?Volume $volume = null;

    public string $severity = self::SEVERITY_OK;

    /** @var string[] What is wrong, in plain words. */
    public array $findings = [];

    /** @var string[] What to do about it. */
    public array $remedies = [];

    public ?string $fsHandle = null;
    public ?string $transformFsHandle = null;

    public bool $fsHasUrls = false;
    public bool $transformFsHasUrls = false;

    /** Local filesystem roots that sit inside the web root — the fetchable-right-now case. */
    public array $exposedPaths = [];

    /** @var AccessRule[] The rules that protect this volume. */
    public array $rules = [];

    public function getIsExposed(): bool
    {
        return $this->severity !== self::SEVERITY_OK;
    }

    public function getIsCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }
}
