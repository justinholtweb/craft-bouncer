<?php

declare(strict_types=1);

namespace justinholtweb\bouncer;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\db\Query as DbQuery;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineAssetUrlEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\Application;
use craft\web\UrlManager;
use justinholtweb\bouncer\behaviors\BouncerQueryBehavior;
use justinholtweb\bouncer\models\Settings;
use justinholtweb\bouncer\services\Access;
use justinholtweb\bouncer\services\Assets;
use justinholtweb\bouncer\services\Exposure;
use justinholtweb\bouncer\services\Files;
use justinholtweb\bouncer\services\Gate;
use justinholtweb\bouncer\services\Guard;
use justinholtweb\bouncer\services\Log;
use justinholtweb\bouncer\services\QueryFilter;
use justinholtweb\bouncer\services\Rules;
use justinholtweb\bouncer\twig\BouncerVariable;
use justinholtweb\bouncer\twig\Extension;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;

/**
 * Bouncer — access rules for Craft CMS.
 *
 * @property-read Rules $rules
 * @property-read Access $access
 * @property-read Guard $guard
 * @property-read QueryFilter $queryFilter
 * @property-read Assets $assets
 * @property-read Files $files
 * @property-read Exposure $exposure
 * @property-read Gate $gate
 * @property-read Log $log
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /**
     * Users with this permission are never refused.
     *
     * Exists so a site can give its editors a way past their own rules without making them admins
     * — the alternative being that somebody switches `exemptCpUsers` on for one person and opens
     * the members area to every freelancer with a CP login.
     */
    public const PERMISSION_BYPASS = 'bouncer:bypass';

    public const LOG_CATEGORY = 'bouncer';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'rules' => Rules::class,
                'access' => Access::class,
                'guard' => Guard::class,
                'queryFilter' => QueryFilter::class,
                'assets' => Assets::class,
                'files' => Files::class,
                'exposure' => Exposure::class,
                'gate' => Gate::class,
                'log' => Log::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerProjectConfig();
        $this->registerPermissions();
        $this->registerRoutes();
        $this->registerTwig();
        $this->registerGarbageCollection();

        // Everything below enforces something, and none of it should run before Craft has
        // finished booting: the rules live in project config, and reading project config during
        // `init()` on a half-installed site is how a plugin makes `craft install` fail.
        Craft::$app->onInit(function() {
            $this->registerRequestLifecycle();
            $this->registerGuard();
            $this->registerQueryFilter();
            $this->registerAssetUrls();
        });
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('bouncer', 'Bouncer');

        $item['subnav']['rules'] = [
            'label' => Craft::t('bouncer', 'Rules'),
            'url' => 'bouncer/rules',
        ];

        if ($this->isPro()) {
            $item['subnav']['exposure'] = [
                'label' => Craft::t('bouncer', 'Files'),
                'url' => 'bouncer/exposure',
            ];

            if ($this->getSettings()->logDenials) {
                $item['subnav']['log'] = [
                    'label' => Craft::t('bouncer', 'Log'),
                    'url' => 'bouncer/log',
                ];
            }
        }

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('bouncer', 'Settings'),
                'url' => 'settings/plugins/bouncer',
            ];
        }

        return $item;
    }

    /**
     * Take the rules out of project config on the way out.
     *
     * Craft only clears `plugins.bouncer`; Bouncer's rules live at a top-level `bouncer` key it
     * knows nothing about. Without this the key outlives the plugin — it turns up in every
     * `project-config/diff` on every environment from then on, and reinstalling silently
     * resurrects the old rules instead of giving the site a clean slate. For an access-control
     * plugin that second part is the dangerous one: rules nobody remembers writing, enforced on
     * content that has moved on. (Family lesson, see `[[project_craft_redpen]]`.)
     */
    public function afterUninstall(): void
    {
        parent::afterUninstall();

        Craft::$app->getProjectConfig()->remove(
            Rules::CONFIG_RULES_KEY,
            'Remove Bouncer’s access rules',
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('bouncer/_settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'isPro' => $this->isPro(),
            'exposures' => $this->isPro() ? $this->exposure->exposures() : [],
            'unevaluable' => $this->rules->getUnevaluableRules(),
        ]);
    }

    // Registration
    // ---------------------------------------------------------------------------------------

    private function registerProjectConfig(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd(Rules::CONFIG_RULES_KEY . '.{uid}', [$this->rules, 'handleChangedRule'])
            ->onUpdate(Rules::CONFIG_RULES_KEY . '.{uid}', [$this->rules, 'handleChangedRule'])
            ->onRemove(Rules::CONFIG_RULES_KEY . '.{uid}', [$this->rules, 'handleDeletedRule']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            $event->config['bouncer']['rules'] = $this->rules->rebuildProjectConfig();
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('bouncer', 'Bouncer'),
                'permissions' => [
                    self::PERMISSION_BYPASS => [
                        'label' => Craft::t('bouncer', 'Bypass all access rules'),
                    ],
                ],
            ];
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'bouncer' => 'bouncer/rules/index',
                'bouncer/rules' => 'bouncer/rules/index',
                'bouncer/rules/new' => 'bouncer/rules/edit',
                'bouncer/rules/<ruleId:\d+>' => 'bouncer/rules/edit',
                'bouncer/exposure' => 'bouncer/exposure/index',
                'bouncer/log' => 'bouncer/log/index',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $uri = $this->getSettings()->getFileRouteUri();

            // The filename variant comes first and both exist: the filename makes a saved
            // download name itself even when a client ignores Content-Disposition, and the bare
            // one keeps working for a URL somebody stored before an asset was renamed.
            $event->rules[$uri . '/<uid:[\w\-]+>/<filename:.+>'] = 'bouncer/file/download';
            $event->rules[$uri . '/<uid:[\w\-]+>'] = 'bouncer/file/download';
        });
    }

    private function registerTwig(): void
    {
        Craft::$app->getView()->registerTwigExtension(new Extension());

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('bouncer', BouncerVariable::class);
        });
    }

    /**
     * The front-door guard.
     *
     * `Controller::EVENT_BEFORE_ACTION` is a class-level handler on Yii's base controller, so it
     * fires for every controller in the application — which is why {@see Guard::shouldGuard()}
     * is as picky as it is.
     */
    private function registerGuard(): void
    {
        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function(ActionEvent $event) {
            // Routing is over by the time any action begins, whatever the action is — so this runs
            // before the guard, and regardless of whether the guard is switched on.
            $this->queryFilter->endRouting();

            $this->guard->handleBeforeAction($event);
        });
    }

    /**
     * Stand the query filter down while Craft is working out what the request is for.
     *
     * Craft matches an element URL by running an element query for that URI. With filtering on,
     * that query hides the protected entry from Craft's own lookup, and the request becomes a
     * plain 404 before the guard ever sees it — so the rule's configured response never happens
     * and the plugin looks like it is silently breaking URLs. See {@see QueryFilter}.
     */
    private function registerRequestLifecycle(): void
    {
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->queryFilter->beginRouting();
        }

        Event::on(Application::class, Application::EVENT_BEFORE_REQUEST, function() {
            $this->queryFilter->beginRouting();
        });
    }

    private function registerQueryFilter(): void
    {
        Event::on(ElementQuery::class, DbQuery::EVENT_DEFINE_BEHAVIORS, static function(DefineBehaviorsEvent $event) {
            $event->behaviors['bouncer'] = BouncerQueryBehavior::class;
        });

        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, function(Event $event) {
            /** @var ElementQuery $query */
            $query = $event->sender;
            $this->queryFilter->handleBeforePrepare($query);
        });
    }

    private function registerAssetUrls(): void
    {
        Event::on(Asset::class, Asset::EVENT_BEFORE_DEFINE_URL, function(DefineAssetUrlEvent $event) {
            $this->assets->handleBeforeDefineUrl($event);
        });
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->log->prune();
        });
    }
}
