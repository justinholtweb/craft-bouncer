<?php
/**
 * Seed a demo of every Bouncer surface into the plugin-testing harness.
 *
 * Run from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bouncer/tests/manual/seed-demo.php
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bouncer/tests/manual/seed-demo.php --clean
 *
 * Unlike `tests/integration/checks.php` this one *leaves things behind* on purpose — the point is
 * to have real protected content at real URLs so the guard, the password gate and the file route
 * can be exercised over HTTP, which is where all of them actually live.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\UserGroup;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\models\RuleAccess;
use justinholtweb\bouncer\models\RuleResponse;
use justinholtweb\bouncer\models\RuleTarget;
use justinholtweb\bouncer\Plugin;

const PREFIX = 'bouncerDemo';
const PASSWORD = 'letmein';

$plugin = Plugin::getInstance();
$entries = Craft::$app->getEntries();
$clean = in_array('--clean', $argv, true);

$removeAll = function() use ($plugin, $entries) {
    foreach ($plugin->rules->getAllRules() as $rule) {
        if (str_starts_with($rule->handle, PREFIX)) {
            $plugin->rules->deleteRule($rule);
        }
    }

    foreach ($entries->getAllSections() as $section) {
        if (str_starts_with($section->handle, PREFIX)) {
            $entries->deleteSection($section);
        }
    }

    foreach ($entries->getAllEntryTypes() as $type) {
        if (str_starts_with($type->handle, PREFIX)) {
            $entries->deleteEntryType($type);
        }
    }

    foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
        if (str_starts_with($group->handle, PREFIX)) {
            Craft::$app->getUserGroups()->deleteGroupById($group->id);
            // Flushed one at a time. Project config coalesces changes inside a request, so a
            // path removed and re-added in the same run fires no handler at all and leaves the
            // database row orphaned — which then fails the uniqueness check on the way back in.
            Craft::$app->getProjectConfig()->saveModifiedConfigData();
        }
    }

    foreach ([PREFIX . 'Member', PREFIX . 'Outsider'] as $username) {
        $user = User::find()->username($username)->status(null)->one();

        if ($user !== null) {
            Craft::$app->getElements()->deleteElement($user, true);
        }
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
};

$removeAll();

if ($clean) {
    echo "Demo removed.\n";
    exit(0);
}

echo "Seeding the Bouncer demo…\n";

// The audience.
//
// Project config writes are buffered in a bare script, so the group's `id` — which Craft resolves
// by looking the freshly written UID up in the database — is null until the config is flushed.
// Assigning a user to a group with a null ID fails silently, and the rule that names the group
// ends up naming nothing. See `[[craft-plugin-gotchas]]`.
$group = new UserGroup(['name' => 'Bouncer Members', 'handle' => PREFIX . 'Members']);

if (!Craft::$app->getUserGroups()->saveGroup($group)) {
    echo "  ! could not save the user group: " . json_encode($group->getErrors()) . "\n";
    exit(1);
}

Craft::$app->getProjectConfig()->saveModifiedConfigData();
$group = Craft::$app->getUserGroups()->getGroupByHandle(PREFIX . 'Members');
echo "  group: {$group->handle} (id {$group->id}, uid {$group->uid})\n";

$member = new User(['username' => PREFIX . 'Member', 'email' => 'bouncer-member@example.test']);
Craft::$app->getElements()->saveElement($member);
Craft::$app->getUsers()->assignUserToGroups($member->id, [$group->id]);
Craft::$app->getUsers()->activateUser($member);
$member->newPassword = 'bouncerdemo1';
Craft::$app->getElements()->saveElement($member);

$outsider = new User(['username' => PREFIX . 'Outsider', 'email' => 'bouncer-outsider@example.test']);
Craft::$app->getElements()->saveElement($outsider);
Craft::$app->getUsers()->activateUser($outsider);
$outsider->newPassword = 'bouncerdemo1';
Craft::$app->getElements()->saveElement($outsider);

// The content.
$entryType = new EntryType(['name' => 'Bouncer Demo', 'handle' => PREFIX . 'Type']);
$entries->saveEntryType($entryType);

$section = new Section([
    'name' => 'Bouncer Demo',
    'handle' => PREFIX . 'Section',
    'type' => Section::TYPE_CHANNEL,
    'entryTypes' => [$entryType],
    'siteSettings' => array_map(
        static fn($site) => new Section_SiteSettings([
            'siteId' => $site->id,
            'hasUrls' => true,
            'uriFormat' => 'bouncer-demo/{slug}',
            'template' => 'bouncer-test',
        ]),
        Craft::$app->getSites()->getAllSites(),
    ),
]);
$entries->saveSection($section);
Craft::$app->getProjectConfig()->saveModifiedConfigData();

foreach (['members-only', 'also-members-only'] as $slug) {
    $entry = new Entry([
        'sectionId' => $section->id,
        'typeId' => $entryType->id,
        'title' => ucwords(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'enabled' => true,
    ]);
    Craft::$app->getElements()->saveElement($entry);
    echo "  entry: /{$entry->uri}\n";
}

$save = function(AccessRule $rule) use ($plugin) {
    if (!$plugin->rules->saveRule($rule)) {
        echo "  ! could not save {$rule->handle}: " . json_encode($rule->getErrors()) . "\n";
        return;
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    echo "  rule: {$rule->handle}\n";
};

// 1. A section behind a user group, refused with a 403 so it can be seen without following a
//    redirect chain.
$save(new AccessRule([
    'name' => 'Demo: members only',
    'handle' => PREFIX . 'Members',
    'target' => new RuleTarget([
        'type' => RuleTarget::TYPE_ENTRIES,
        'sourceUids' => [$section->uid],
    ]),
    'access' => new RuleAccess(['userGroupUids' => [$group->uid]]),
    'response' => new RuleResponse([
        'type' => RuleResponse::TYPE_FORBIDDEN,
        'message' => 'Members only.',
    ]),
]));

// 2. A URI pattern behind a shared password.
$save(new AccessRule([
    'name' => 'Demo: password wall',
    'handle' => PREFIX . 'Password',
    'target' => new RuleTarget([
        'type' => RuleTarget::TYPE_URI,
        'uriPatterns' => ['bouncer-gate/**'],
    ]),
    'access' => new RuleAccess([
        'passwordHash' => Craft::$app->getSecurity()->generatePasswordHash(PASSWORD),
    ]),
    'response' => new RuleResponse([
        'type' => RuleResponse::TYPE_PASSWORD,
        'message' => 'This area is password protected.',
    ]),
]));

// 3. A URI behind a template response — the paywall shape: the reader gets a real page, at the
//    real URL, with a 403 so search engines do not index it as the content it is withholding.
$save(new AccessRule([
    'name' => 'Demo: paywall template',
    'handle' => PREFIX . 'Template',
    'target' => new RuleTarget([
        'type' => RuleTarget::TYPE_URI,
        'uriPatterns' => ['bouncer-paywall/**'],
    ]),
    'access' => new RuleAccess(['requireLogin' => true]),
    'response' => new RuleResponse([
        'type' => RuleResponse::TYPE_TEMPLATE,
        'template' => 'bouncer-paywall/_locked',
        'message' => 'Subscribers only.',
    ]),
]));

// 4. A URI behind a redirect, which is what most sites reach for first.
$save(new AccessRule([
    'name' => 'Demo: redirect',
    'handle' => PREFIX . 'Redirect',
    'target' => new RuleTarget([
        'type' => RuleTarget::TYPE_URI,
        'uriPatterns' => ['bouncer-redirect/**'],
    ]),
    'access' => new RuleAccess(['requireLogin' => true]),
    'response' => new RuleResponse([
        'type' => RuleResponse::TYPE_REDIRECT,
        'redirectUrl' => 'bouncer-paywall',
    ]),
]));

// 5. Every asset volume, behind being logged in.
$volumes = Craft::$app->getVolumes()->getAllVolumes();

if ($volumes !== []) {
    $save(new AccessRule([
        'name' => 'Demo: protected files',
        'handle' => PREFIX . 'Files',
        'target' => new RuleTarget([
            'type' => RuleTarget::TYPE_ASSETS,
            'sourceUids' => [$volumes[0]->uid],
        ]),
        'access' => new RuleAccess(['requireLogin' => true]),
        'response' => new RuleResponse(['type' => RuleResponse::TYPE_FORBIDDEN]),
    ]));

    $asset = craft\elements\Asset::find()->volumeId($volumes[0]->id)->kind('image')->one();

    if ($asset !== null) {
        echo "  asset: {$asset->filename} (uid {$asset->uid})\n";
        echo "  guarded: " . $plugin->assets->guardedUrl($asset) . "\n";
        echo "  signed:  " . $plugin->assets->signedUrl($asset, 900) . "\n";
    }
}

echo "\nUsers: " . PREFIX . "Member / bouncerdemo1 (in the group), " . PREFIX . "Outsider / bouncerdemo1 (not)\n";
echo "Password wall: /bouncer-gate/anything, password “" . PASSWORD . "”\n";
echo "Edition: " . (Craft::$app->getPlugins()->getPluginInfo('bouncer')['edition'] ?? '?') . "\n";
