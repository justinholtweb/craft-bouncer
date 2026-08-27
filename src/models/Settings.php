<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\models;

use Craft;
use craft\base\Model;

/**
 * Plugin settings.
 *
 * Nothing here is `required` — a settings model with a required attribute cannot be saved by the
 * installer and the plugin then fails to install at all (see `[[craft-plugin-gotchas]]`).
 *
 * The defaults are chosen so that a fresh install is *safe* rather than convenient: query
 * filtering on, CP unaffected, admins exempt, log off. The one place that trades the other way is
 * {@see self::$filterQueries}, which is on because a rule that hides the page but not the listing
 * is not a rule anybody asked for.
 */
class Settings extends Model
{
    /**
     * Guard front-end page requests.
     *
     * The main switch. Off is a way to stage rules — build them, check them with the tester, then
     * turn this on — and it is the first thing to try when a site locks itself out.
     */
    public bool $enforceRequests = true;

    /**
     * Filter protected elements out of element queries on the front end.
     *
     * This is what stops a protected entry's title appearing in a listing, a search result, a
     * sitemap, an RSS feed or a GraphQL response — they all run element queries, so one hook
     * covers all of them.
     */
    public bool $filterQueries = true;

    /**
     * Also filter element queries in the control panel.
     *
     * Off, and it should stay off. The CP has its own permissions system, and an author who
     * cannot see the entry they are supposed to be editing files a bug against you, not against
     * the rule.
     */
    public bool $filterCpQueries = false;

    /**
     * Admins are never refused.
     *
     * On by default because the alternative is a plugin whose first act on a misconfigured rule is
     * to lock the person who installed it out of their own site.
     */
    public bool $exemptAdmins = true;

    /**
     * Users who can access the CP are never refused.
     *
     * Off by default — "can log into the control panel" is a much wider group than "should read
     * the members area", and conflating them is how paid content leaks to freelancers.
     */
    public bool $exemptCpUsers = false;

    /**
     * The maximum number of element IDs a condition-based rule will resolve for query filtering.
     *
     * Condition rules cannot be expressed as a join, so filtering a listing means asking which
     * elements the condition matches and excluding those IDs. That is fine at a few thousand and
     * silly at a million. Past the cap Bouncer stops filtering *by that rule* rather than
     * truncating — a half-applied exclusion is a leak with no error message — and logs a warning
     * naming the rule.
     */
    public int $conditionIdCap = 5000;

    /** How long resolved condition IDs are cached, in seconds. */
    public int $conditionCacheDuration = 300;

    // Files (Pro)
    // -------------------------------------------------------------------------------------------

    /**
     * Rewrite protected assets' URLs to Bouncer's guarded route.
     *
     * Without this, an asset rule filters the element out of queries and does nothing whatever
     * about the file, which is the exact failure the plugin exists to prevent.
     */
    public bool $guardAssetUrls = true;

    /** The front-end URI the guarded route lives at. */
    public string $fileRouteUri = 'bouncer/file';

    /** How long a signed URL is valid for, in seconds. */
    public int $signedUrlDuration = 3600;

    /**
     * Serve files by handing off to the web server instead of streaming through PHP.
     *
     * `null` streams through PHP, which always works. `x-accel-redirect` (nginx) and `x-sendfile`
     * (Apache with mod_xsendfile) are much cheaper for large files but need the server configured
     * to match — and configured *with an internal location*, or the hand-off becomes a public
     * URL and undoes the whole plugin.
     */
    public ?string $fileDeliveryMethod = null;

    /**
     * Maps a local filesystem root to the internal location the web server exposes it at.
     *
     * Only used by the hand-off methods. Keys are filesystem paths, values are internal URI
     * prefixes: `['/var/www/private' => '/internal-files']`.
     */
    public array $internalPathMap = [];

    /** Send protected files as downloads rather than letting the browser display them inline. */
    public bool $forceDownload = false;

    // Gate (Pro)
    // -------------------------------------------------------------------------------------------

    /** The template rendered for the password prompt. Empty uses the one Bouncer ships. */
    public ?string $passwordTemplate = null;

    /** Failed password attempts allowed per IP, per rule, inside the window below. */
    public int $passwordAttempts = 10;

    public int $passwordAttemptWindow = 300;

    // Log (Pro)
    // -------------------------------------------------------------------------------------------

    public bool $logDenials = false;

    /** Also record the requests that were let through. Noisy, and occasionally exactly the point. */
    public bool $logAllowed = false;

    /** Days of log kept. Garbage collection prunes past this. */
    public int $logRetentionDays = 30;

    public function attributeLabels(): array
    {
        return [
            'enforceRequests' => Craft::t('bouncer', 'Guard front-end requests'),
            'filterQueries' => Craft::t('bouncer', 'Filter element queries'),
            'filterCpQueries' => Craft::t('bouncer', 'Filter control panel queries'),
            'exemptAdmins' => Craft::t('bouncer', 'Admins are exempt'),
            'exemptCpUsers' => Craft::t('bouncer', 'Control panel users are exempt'),
            'conditionIdCap' => Craft::t('bouncer', 'Condition ID cap'),
            'guardAssetUrls' => Craft::t('bouncer', 'Guard asset URLs'),
            'fileRouteUri' => Craft::t('bouncer', 'File route URI'),
            'signedUrlDuration' => Craft::t('bouncer', 'Signed URL lifetime'),
            'fileDeliveryMethod' => Craft::t('bouncer', 'File delivery method'),
            'forceDownload' => Craft::t('bouncer', 'Force download'),
            'passwordAttempts' => Craft::t('bouncer', 'Password attempts'),
            'logDenials' => Craft::t('bouncer', 'Log refusals'),
            'logRetentionDays' => Craft::t('bouncer', 'Log retention'),
        ];
    }

    protected function defineRules(): array
    {
        return [
            [
                [
                    'enforceRequests', 'filterQueries', 'filterCpQueries', 'exemptAdmins', 'exemptCpUsers',
                    'guardAssetUrls', 'forceDownload', 'logDenials', 'logAllowed',
                ],
                'boolean',
            ],
            [['conditionIdCap', 'conditionCacheDuration', 'signedUrlDuration', 'passwordAttempts', 'passwordAttemptWindow'], 'integer', 'min' => 0],
            [['logRetentionDays'], 'integer', 'min' => 1],
            [['fileRouteUri'], 'string'],
            [['fileRouteUri'], 'match', 'pattern' => '/^[a-z0-9\-_\/]+$/i', 'message' => Craft::t('bouncer', 'Use letters, numbers, dashes and slashes only.')],
            [['fileDeliveryMethod'], 'in', 'range' => [null, '', 'x-accel-redirect', 'x-sendfile']],
            [['passwordTemplate', 'internalPathMap'], 'safe'],
            [['internalPathMap'], 'validateInternalPathMap', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A hand-off method with no path map serves nothing at all, and the symptom is an empty 200 —
     * so it is worth being told at the moment the setting is saved.
     */
    public function validateInternalPathMap(): void
    {
        if (in_array($this->fileDeliveryMethod, ['x-accel-redirect', 'x-sendfile'], true) && $this->getInternalPathMap() === []) {
            $this->addError('internalPathMap', Craft::t('bouncer', 'Map at least one filesystem path to an internal location, or files will be served empty.'));
        }
    }

    /**
     * The path map as `path => internal location`.
     *
     * Craft's editable table posts rows (`[['path' => …, 'location' => …]]`), not a map — see
     * `[[craft-plugin-gotchas]]`. Normalising on read rather than in a setter keeps the stored
     * shape whatever the CP posted, so a hand-edited `config/bouncer.php` can use the obvious
     * map form and both work.
     */
    public function getInternalPathMap(): array
    {
        $map = [];

        foreach ($this->internalPathMap as $key => $value) {
            if (is_array($value)) {
                $path = trim((string)($value['path'] ?? ''));
                $location = trim((string)($value['location'] ?? ''));
            } else {
                $path = trim((string)$key);
                $location = trim((string)$value);
            }

            if ($path !== '' && $location !== '') {
                $map[rtrim($path, '/')] = '/' . trim($location, '/');
            }
        }

        return $map;
    }

    public function getFileRouteUri(): string
    {
        return trim($this->fileRouteUri ?: 'bouncer/file', '/');
    }
}
