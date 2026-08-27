<?php
/**
 * Bouncer integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bouncer/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: real rules through project config, a real section with real
 * entries, the element query filter as SQL against the actual database, the signed file reference,
 * and the edition boundary in both directions.
 *
 * Idempotent and self-cleaning. Every run creates its own section, entries, user group and rules
 * under a `bouncerCheck` prefix and removes them at the end — including strays from a run that
 * died half way, because a leftover access rule on a shared harness is a rule that quietly
 * protects somebody else's test content.
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
use justinholtweb\bouncer\helpers\Ip;
use justinholtweb\bouncer\helpers\Teaser;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\models\RuleAccess;
use justinholtweb\bouncer\models\RuleResponse;
use justinholtweb\bouncer\models\RuleTarget;
use justinholtweb\bouncer\models\Verdict;
use justinholtweb\bouncer\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

const PREFIX = 'bouncerCheck';

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Bouncer is not installed.\n";
    exit(1);
}

// ---------------------------------------------------------------------------------------------
// Clean up anything a previous run left behind, before anything else reads the rule list.
// ---------------------------------------------------------------------------------------------

$cleanup = function() use ($plugin) {
    foreach ($plugin->rules->getAllRules() as $rule) {
        if (str_starts_with($rule->handle, PREFIX)) {
            $plugin->rules->deleteRule($rule);
        }
    }

    $entriesService = Craft::$app->getEntries();

    foreach ($entriesService->getAllSections() as $sectionModel) {
        if (str_starts_with($sectionModel->handle, PREFIX)) {
            $entriesService->deleteSection($sectionModel);
        }
    }

    foreach ($entriesService->getAllEntryTypes() as $entryType) {
        if (str_starts_with($entryType->handle, PREFIX)) {
            $entriesService->deleteEntryType($entryType);
        }
    }

    foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
        if (str_starts_with($group->handle, PREFIX)) {
            Craft::$app->getUserGroups()->deleteGroupById($group->id);
        }
    }

    $user = User::find()->username(PREFIX . 'User')->status(null)->one();

    if ($user !== null) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
};

$cleanup();

$originalEdition = Craft::$app->getPlugins()->getPluginInfo('bouncer')['edition'] ?? Plugin::EDITION_LITE;

$setEdition = function(string $edition) use ($plugin) {
    Craft::$app->getPlugins()->switchEdition('bouncer', $edition);
    // The evaluator memoizes the licence answer for the request, which is right in production and
    // wrong in a script that changes edition half way through.
    $reflection = new ReflectionObject($plugin->access);
    $property = $reflection->getProperty('_isPro');
    $property->setAccessible(true);
    $property->setValue($plugin->access, null);
    $plugin->access->clearCache();
};

echo "Bouncer checks\n==============\n";

// ---------------------------------------------------------------------------------------------
section('IP matching');
// ---------------------------------------------------------------------------------------------

check('an exact IPv4 address matches itself', fn() => Ip::matches('203.0.113.7', '203.0.113.7') === true);
check('a different IPv4 address does not', fn() => Ip::matches('203.0.113.8', '203.0.113.7') === false);
check('a /24 contains its members', fn() => Ip::matches('203.0.113.99', '203.0.113.0/24') === true);
check('a /24 excludes its neighbours', fn() => Ip::matches('203.0.114.1', '203.0.113.0/24') === false);
check('a /8 matches on the first byte only', fn() => Ip::matches('10.255.255.255', '10.0.0.0/8') === true);
check('a non-byte-aligned prefix works', fn() => Ip::matches('192.168.1.5', '192.168.0.0/23') === true);
check('and excludes what falls outside it', fn() => Ip::matches('192.168.2.5', '192.168.0.0/23') === false);
check('/0 matches everything', fn() => Ip::matches('8.8.8.8', '0.0.0.0/0') === true);
check('/32 is an exact match', fn() => Ip::matches('8.8.8.8', '8.8.8.8/32') === true && Ip::matches('8.8.8.9', '8.8.8.8/32') === false);
check('IPv6 exact matching works', fn() => Ip::matches('2001:db8::1', '2001:db8::1') === true);
check('IPv6 ranges work', fn() => Ip::matches('2001:db8::dead:beef', '2001:db8::/32') === true);
check('an IPv6 outside the range is excluded', fn() => Ip::matches('2001:db9::1', '2001:db8::/32') === false);
check(
    'a v4 address is never inside a v6 range',
    fn() => Ip::matches('127.0.0.1', '::/0') === false,
);
check('garbage never matches', fn() => Ip::matches('not-an-ip', '0.0.0.0/0') === false);
check('an invalid pattern is rejected up front', fn() => Ip::isValidPattern('10.0.0.0/64') === false);
check('a valid CIDR is accepted', fn() => Ip::isValidPattern('10.0.0.0/8') === true);
check('an IPv6 /129 is rejected', fn() => Ip::isValidPattern('2001:db8::/129') === false);
check('matchesAny short-circuits correctly', fn() => Ip::matchesAny('10.1.2.3', ['8.8.8.8', '10.0.0.0/8']) === true);
check('matchesAny returns false on an empty list', fn() => Ip::matchesAny('10.1.2.3', []) === false);

// ---------------------------------------------------------------------------------------------
section('URI patterns');
// ---------------------------------------------------------------------------------------------

check('an exact pattern matches', fn() => RuleTarget::uriMatchesPattern('members', 'members') === true);
check('a single star stays inside one segment', fn() => RuleTarget::uriMatchesPattern('members/welcome', 'members/*') === true);
check('and does not cross a slash', fn() => RuleTarget::uriMatchesPattern('members/a/b', 'members/*') === false);
check('a double star crosses slashes', fn() => RuleTarget::uriMatchesPattern('members/a/b', 'members/**') === true);
check(
    'a trailing /** also covers the bare prefix, which is what everybody means',
    fn() => RuleTarget::uriMatchesPattern('members', 'members/**') === true,
);
check('** on its own matches everything', fn() => RuleTarget::uriMatchesPattern('anything/at/all', '**') === true);
check('__home__ matches the empty URI', fn() => RuleTarget::uriMatchesPattern('', '__home__') === true);
check('__home__ matches nothing else', fn() => RuleTarget::uriMatchesPattern('members', '__home__') === false);
check('an empty pattern matches nothing', fn() => RuleTarget::uriMatchesPattern('members', '') === false);
check('leading slashes are irrelevant', fn() => RuleTarget::uriMatchesPattern('/members/x', '/members/*') === true);
check(
    'a dot in a pattern is literal, not a regex wildcard',
    fn() => RuleTarget::uriMatchesPattern('axfeed', 'a.feed') === false,
);
check('a partial prefix does not match', fn() => RuleTarget::uriMatchesPattern('membership', 'members') === false);

// ---------------------------------------------------------------------------------------------
section('Teasers');
// ---------------------------------------------------------------------------------------------

check('a short text is returned whole', fn() => Teaser::words('<p>One two three</p>', 10) === 'One two three');
check('a long text is cut at the word count', fn() => Teaser::words('<p>one two three four five</p>', 3) === 'one two three…');
check(
    'block tags become a space, so paragraphs do not run together',
    fn() => Teaser::toText('<p>One.</p><p>Two.</p>') === 'One. Two.',
);
check('entities are decoded', fn() => Teaser::toText('<p>Fish &amp; chips</p>') === 'Fish & chips');
check('character truncation cuts on a word boundary', function() {
    $result = Teaser::characters('<p>subscribe today for everything</p>', 12);

    return $result === 'subscribe…' ?: "got: $result";
});
check('zero words returns nothing', fn() => Teaser::words('<p>hello</p>', 0) === '');

// ---------------------------------------------------------------------------------------------
section('The edition boundary');
// ---------------------------------------------------------------------------------------------

check('Lite does not allow asset targets', fn() => Edition::allowsTargetType(RuleTarget::TYPE_ASSETS, false) === false);
check('Pro does', fn() => Edition::allowsTargetType(RuleTarget::TYPE_ASSETS, true) === true);
check('Lite allows entries, categories and URIs', fn() => Edition::allowedTargetTypes(false) === [
    RuleTarget::TYPE_ENTRIES, RuleTarget::TYPE_CATEGORIES, RuleTarget::TYPE_URI,
]);
check('passwords, dates, IP rules and conditions are all Pro', fn() => !Edition::allowsPassword(false)
    && !Edition::allowsDateWindow(false)
    && !Edition::allowsIpRules(false)
    && !Edition::allowsElementCondition(false));
check('an access model with only Lite requirements is evaluable on Lite', function() {
    $access = new RuleAccess(['requireLogin' => true]);

    return $access->getHasEvaluableRequirement(false) === true;
});
check('an access model with only a password is not', function() {
    $access = new RuleAccess(['passwordHash' => '$2y$13$abcdefghijklmnopqrstuv']);

    return $access->getHasEvaluableRequirement(false) === false
        && $access->getHasEvaluableRequirement(true) === true;
});
check('and Bouncer fails closed rather than open', fn() => Edition::deniesUnevaluable() === true);

// ---------------------------------------------------------------------------------------------
section('Date windows and IP rules');
// ---------------------------------------------------------------------------------------------

check('no window means no objection', function() {
    return (new RuleAccess())->checkDateWindow() === null;
});
check('a window that has not opened says “not yet”', function() {
    $access = new RuleAccess(['startDate' => (new DateTime('+1 day'))->format('c')]);

    return $access->checkDateWindow() === Verdict::REASON_NOT_YET;
});
check('a window that has closed says “expired”', function() {
    $access = new RuleAccess(['endDate' => (new DateTime('-1 day'))->format('c')]);

    return $access->checkDateWindow() === Verdict::REASON_EXPIRED;
});
check('an open window is satisfied', function() {
    $access = new RuleAccess([
        'startDate' => (new DateTime('-1 day'))->format('c'),
        'endDate' => (new DateTime('+1 day'))->format('c'),
    ]);

    return $access->checkDateWindow() === null;
});
check('an end date before the start date is a validation error', function() {
    $access = new RuleAccess([
        'startDate' => (new DateTime('+2 days'))->format('c'),
        'endDate' => (new DateTime('+1 day'))->format('c'),
    ]);

    return $access->validate() === false && $access->hasErrors('endDate');
});
check('allow mode lets listed addresses through and refuses the rest', function() {
    $access = new RuleAccess(['ipMode' => RuleAccess::IP_MODE_ALLOW, 'ips' => ['10.0.0.0/8']]);

    return $access->checkIp('10.1.1.1') === true && $access->checkIp('8.8.8.8') === false;
});
check('deny mode is the mirror image', function() {
    $access = new RuleAccess(['ipMode' => RuleAccess::IP_MODE_DENY, 'ips' => ['10.0.0.0/8']]);

    return $access->checkIp('10.1.1.1') === false && $access->checkIp('8.8.8.8') === true;
});
check('an unknown address never satisfies allow mode', function() {
    $access = new RuleAccess(['ipMode' => RuleAccess::IP_MODE_ALLOW, 'ips' => ['10.0.0.0/8']]);

    return $access->checkIp(null) === false;
});
check('IP mode off ignores the list entirely', function() {
    $access = new RuleAccess(['ipMode' => RuleAccess::IP_MODE_OFF, 'ips' => ['10.0.0.0/8']]);

    return $access->checkIp('8.8.8.8') === true;
});
check('an invalid address in the list is a validation error', function() {
    $access = new RuleAccess(['ipMode' => RuleAccess::IP_MODE_ALLOW, 'ips' => ['not-an-ip']]);

    return $access->validate() === false && $access->hasErrors('ips');
});
check('an empty list with IP mode on is a validation error too', function() {
    // Yii skips inline validators on empty attributes, so this is exactly the case that would go
    // unchecked without `skipOnEmpty => false`.
    $access = new RuleAccess(['ipMode' => RuleAccess::IP_MODE_ALLOW, 'ips' => []]);

    return $access->validate() === false && $access->hasErrors('ips');
});

// ---------------------------------------------------------------------------------------------
section('Passwords');
// ---------------------------------------------------------------------------------------------

check('a hashed password validates', function() {
    $hash = Craft::$app->getSecurity()->generatePasswordHash('open sesame');
    $access = new RuleAccess(['passwordHash' => $hash]);

    return $access->checkPassword('open sesame') === true && $access->checkPassword('nope') === false;
});
check('no password means nothing validates', function() {
    return (new RuleAccess())->checkPassword('anything') === false;
});
check('an unset environment variable refuses everything, including its own name', function() {
    $access = new RuleAccess(['passwordHash' => '$BOUNCER_CHECK_UNSET_VAR']);

    return $access->checkPassword('$BOUNCER_CHECK_UNSET_VAR') === false
        && $access->checkPassword('') === false;
});
check('a set environment variable compares as plain text', function() {
    putenv('BOUNCER_CHECK_PASSWORD=hunter2');
    $_SERVER['BOUNCER_CHECK_PASSWORD'] = 'hunter2';

    $access = new RuleAccess(['passwordHash' => '$BOUNCER_CHECK_PASSWORD']);
    $result = $access->checkPassword('hunter2') === true && $access->checkPassword('hunter3') === false;

    putenv('BOUNCER_CHECK_PASSWORD');
    unset($_SERVER['BOUNCER_CHECK_PASSWORD']);

    return $result;
});

// ---------------------------------------------------------------------------------------------
section('Rules through project config');
// ---------------------------------------------------------------------------------------------

$makeRule = function(string $handle, array $config = []) use ($plugin): AccessRule {
    $rule = new AccessRule(array_merge([
        'name' => "Check $handle",
        'handle' => $handle,
        'target' => new RuleTarget(['type' => RuleTarget::TYPE_URI, 'uriPatterns' => ["$handle/**"]]),
        'access' => new RuleAccess(['requireLogin' => true]),
        'response' => new RuleResponse(['type' => RuleResponse::TYPE_FORBIDDEN]),
    ], $config));

    $plugin->rules->saveRule($rule);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    return $rule;
};

check('a rule saves and gets an ID', function() use ($makeRule) {
    $rule = $makeRule(PREFIX . 'Basic');

    return $rule->id !== null && $rule->uid !== null;
});

check('and reads back from the mirror table', function() use ($plugin) {
    $rule = $plugin->rules->getRuleByHandle(PREFIX . 'Basic');

    return $rule !== null
        && $rule->target->type === RuleTarget::TYPE_URI
        && $rule->target->uriPatterns === [PREFIX . 'Basic/**']
        && $rule->access->requireLogin === true
        && $rule->response->type === RuleResponse::TYPE_FORBIDDEN;
});

check('it is in project config, not only in the database', function() {
    $config = Craft::$app->getProjectConfig()->get('bouncer.rules');

    foreach ($config ?? [] as $ruleConfig) {
        if (($ruleConfig['handle'] ?? null) === PREFIX . 'Basic') {
            return true;
        }
    }

    return 'not found in project config';
});

check('a duplicate handle is refused', function() use ($plugin) {
    $rule = new AccessRule([
        'name' => 'Duplicate',
        'handle' => PREFIX . 'Basic',
        'target' => new RuleTarget(['type' => RuleTarget::TYPE_URI, 'uriPatterns' => ['x/**']]),
        'access' => new RuleAccess(['requireLogin' => true]),
    ]);

    return $plugin->rules->saveRule($rule) === false && $rule->hasErrors('handle');
});

check('a rule with no requirements at all is refused', function() {
    $rule = new AccessRule([
        'name' => 'Empty',
        'handle' => PREFIX . 'Empty',
        'target' => new RuleTarget(['type' => RuleTarget::TYPE_URI, 'uriPatterns' => ['x/**']]),
        'access' => new RuleAccess(),
    ]);

    return $rule->validate() === false && $rule->hasErrors('access');
});

check('a URI rule with no patterns is refused', function() {
    $rule = new AccessRule([
        'name' => 'No patterns',
        'handle' => PREFIX . 'NoPatterns',
        'target' => new RuleTarget(['type' => RuleTarget::TYPE_URI]),
        'access' => new RuleAccess(['requireLogin' => true]),
    ]);

    return $rule->validate() === false && $rule->hasErrors('target.uriPatterns');
});

check('an entries rule with no sources is refused', function() {
    $rule = new AccessRule([
        'name' => 'No sources',
        'handle' => PREFIX . 'NoSources',
        'target' => new RuleTarget(['type' => RuleTarget::TYPE_ENTRIES]),
        'access' => new RuleAccess(['requireLogin' => true]),
    ]);

    return $rule->validate() === false && $rule->hasErrors('target.sourceUids');
});

check('a password response with no password is refused', function() {
    $rule = new AccessRule([
        'name' => 'No password',
        'handle' => PREFIX . 'NoPassword',
        'target' => new RuleTarget(['type' => RuleTarget::TYPE_URI, 'uriPatterns' => ['x/**']]),
        'access' => new RuleAccess(['requireLogin' => true]),
        'response' => new RuleResponse(['type' => RuleResponse::TYPE_PASSWORD]),
    ]);

    return $rule->validate() === false && $rule->hasErrors('response.type');
});

check('deleting a rule removes the mirror row as well as the config', function() use ($plugin, $makeRule) {
    $rule = $makeRule(PREFIX . 'Doomed');
    $id = $rule->id;

    $plugin->rules->deleteRule($rule);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    $gone = $plugin->rules->getRuleById($id) === null;
    $row = (new craft\db\Query())->from('{{%bouncer_rules}}')->where(['id' => $id])->exists();

    return $gone && !$row;
});

// ---------------------------------------------------------------------------------------------
section('Evaluation');
// ---------------------------------------------------------------------------------------------

$setEdition(Plugin::EDITION_PRO);

// A group and a user to evaluate against.
$group = new UserGroup(['name' => 'Bouncer Check Group', 'handle' => PREFIX . 'Group']);
Craft::$app->getUserGroups()->saveGroup($group);

$user = new User([
    'username' => PREFIX . 'User',
    'email' => 'bouncer-check@example.test',
    'firstName' => 'Bouncer',
    'lastName' => 'Check',
]);
Craft::$app->getElements()->saveElement($user);
Craft::$app->getUsers()->assignUserToGroups($user->id, [$group->id]);
$user = Craft::$app->getUsers()->getUserById($user->id);

check('an anonymous visitor is refused a login-required rule', function() use ($plugin, $makeRule) {
    $rule = $makeRule(PREFIX . 'Login');
    $verdict = $plugin->access->checkRule($rule, null);

    return $verdict->allowed === false && $verdict->reason === Verdict::REASON_LOGIN;
});

check('a logged-in user passes it', function() use ($plugin, $user) {
    $rule = $plugin->rules->getRuleByHandle(PREFIX . 'Login');

    return $plugin->access->checkRule($rule, $user)->allowed === true;
});

check('a user outside the required group is refused', function() use ($plugin, $user, $makeRule) {
    $other = new UserGroup(['name' => 'Bouncer Check Other', 'handle' => PREFIX . 'Other']);
    Craft::$app->getUserGroups()->saveGroup($other);

    $rule = $makeRule(PREFIX . 'Group', [
        'access' => new RuleAccess(['userGroupUids' => [$other->uid]]),
    ]);

    $verdict = $plugin->access->checkRule($rule, $user);

    return $verdict->allowed === false && $verdict->reason === Verdict::REASON_GROUP;
});

check('a user inside it passes', function() use ($plugin, $user, $group, $makeRule) {
    $rule = $makeRule(PREFIX . 'InGroup', [
        'access' => new RuleAccess(['userGroupUids' => [$group->uid]]),
    ]);

    return $plugin->access->checkRule($rule, $user)->allowed === true;
});

check('“all groups” needs all of them', function() use ($plugin, $user, $group, $makeRule) {
    $other = Craft::$app->getUserGroups()->getGroupByHandle(PREFIX . 'Other');

    $rule = $makeRule(PREFIX . 'AllGroups', [
        'access' => new RuleAccess([
            'userGroupUids' => [$group->uid, $other->uid],
            'groupMatch' => RuleAccess::GROUP_MATCH_ALL,
        ]),
    ]);

    return $plugin->access->checkRule($rule, $user)->allowed === false;
});

check('“any group” needs only one', function() use ($plugin, $user, $group, $makeRule) {
    $other = Craft::$app->getUserGroups()->getGroupByHandle(PREFIX . 'Other');

    $rule = $makeRule(PREFIX . 'AnyGroup', [
        'access' => new RuleAccess([
            'userGroupUids' => [$group->uid, $other->uid],
            'groupMatch' => RuleAccess::GROUP_MATCH_ANY,
        ]),
    ]);

    return $plugin->access->checkRule($rule, $user)->allowed === true;
});

check('an expired date window refuses even a logged-in user', function() use ($plugin, $user, $makeRule) {
    $rule = $makeRule(PREFIX . 'Expired', [
        'access' => new RuleAccess([
            'requireLogin' => true,
            'endDate' => (new DateTime('-1 day'))->format('c'),
        ]),
    ]);

    $verdict = $plugin->access->checkRule($rule, $user);

    return $verdict->allowed === false && $verdict->reason === Verdict::REASON_EXPIRED;
});

check('a password rule reports that a password would unlock it', function() use ($plugin, $makeRule) {
    $rule = $makeRule(PREFIX . 'Password', [
        'access' => new RuleAccess([
            'passwordHash' => Craft::$app->getSecurity()->generatePasswordHash('sesame'),
        ]),
        'response' => new RuleResponse(['type' => RuleResponse::TYPE_PASSWORD]),
    ]);

    $verdict = $plugin->access->checkRule($rule, null);

    return $verdict->allowed === false
        && $verdict->reason === Verdict::REASON_PASSWORD
        && $verdict->passwordWouldUnlock === true;
});

check('on Lite the same rule denies as unevaluable rather than opening', function() use ($plugin, $setEdition) {
    $setEdition(Plugin::EDITION_LITE);

    $rule = $plugin->rules->getRuleByHandle(PREFIX . 'Password');
    $verdict = $plugin->access->checkRule($rule, null);

    $result = $verdict->allowed === false && $verdict->reason === Verdict::REASON_UNEVALUABLE;

    $setEdition(Plugin::EDITION_PRO);

    return $result;
});

check('the unevaluable rules list names it', function() use ($plugin, $setEdition) {
    $setEdition(Plugin::EDITION_LITE);

    $handles = array_map(static fn($r) => $r->handle, $plugin->rules->getUnevaluableRules());

    $setEdition(Plugin::EDITION_PRO);

    return in_array(PREFIX . 'Password', $handles, true);
});

// ---------------------------------------------------------------------------------------------
section('Elements and the query filter');
// ---------------------------------------------------------------------------------------------

$entriesService = Craft::$app->getEntries();

$entryType = new EntryType(['name' => 'Bouncer Check', 'handle' => PREFIX . 'Type']);
$entriesService->saveEntryType($entryType);

$sectionModel = new Section([
    'name' => 'Bouncer Check',
    'handle' => PREFIX . 'Section',
    'type' => Section::TYPE_CHANNEL,
    'entryTypes' => [$entryType],
    'siteSettings' => array_map(
        static fn($site) => new Section_SiteSettings([
            'siteId' => $site->id,
            'hasUrls' => true,
            'uriFormat' => PREFIX . '/{slug}',
            'template' => 'index',
        ]),
        Craft::$app->getSites()->getAllSites(),
    ),
]);
$entriesService->saveSection($sectionModel);
Craft::$app->getProjectConfig()->saveModifiedConfigData();

$entryIds = [];

foreach (['alpha', 'beta', 'gamma'] as $slug) {
    $entry = new Entry([
        'sectionId' => $sectionModel->id,
        'typeId' => $entryType->id,
        'title' => "Bouncer check $slug",
        'slug' => PREFIX . '-' . $slug,
        'enabled' => true,
    ]);
    Craft::$app->getElements()->saveElement($entry);
    $entryIds[] = $entry->id;
}

check('the fixtures exist', fn() => count($entryIds) === 3 && Entry::find()->sectionId($sectionModel->id)->count() == 3);

$entryRule = $makeRule(PREFIX . 'Section', [
    'target' => new RuleTarget([
        'type' => RuleTarget::TYPE_ENTRIES,
        'sourceUids' => [$sectionModel->uid],
    ]),
    'access' => new RuleAccess(['userGroupUids' => [$group->uid]]),
]);

check('a section rule matches an entry in that section', function() use ($plugin, $entryIds) {
    $entry = Entry::find()->id($entryIds[0])->one();

    return $plugin->access->matchingRules($entry) !== [];
});

check('an anonymous visitor is refused it', function() use ($plugin, $entryIds) {
    $plugin->access->clearCache();
    $entry = Entry::find()->id($entryIds[0])->one();

    return $plugin->access->checkElement($entry, null)->allowed === false;
});

check('a member of the group is allowed it', function() use ($plugin, $entryIds, $user) {
    $plugin->access->clearCache();
    $entry = Entry::find()->id($entryIds[0])->one();

    return $plugin->access->checkElement($entry, $user)->allowed === true;
});

check('an entry no rule targets is untouched', function() use ($plugin, $sectionModel) {
    $plugin->access->clearCache();

    // Picked by asking Bouncer which entries nothing covers, rather than by assuming the rest of
    // the install is unprotected — this harness is shared, and the demo seed puts real rules on
    // real sections.
    foreach (Entry::find()->sectionId(['not', $sectionModel->id])->status(null)->limit(50)->all() as $other) {
        if ($plugin->access->matchingRules($other) === []) {
            return $plugin->access->checkElement($other, null)->allowed === true;
        }
    }

    return true; // Everything else on this install is protected by something; nothing to compare.
});

check('a console request is not filtered by default — there is no visitor to refuse', function() use ($plugin, $sectionModel) {
    $plugin->access->clearCache();

    $query = Entry::find()->sectionId($sectionModel->id);
    $plugin->queryFilter->handleBeforePrepare($query);

    $count = (int)$query->count();

    return $count === 3 ?: "expected 3, got $count";
});

check('.bouncer(true) opts a query in, and the exclusion SQL removes them', function() use ($plugin, $sectionModel) {
    $plugin->access->clearCache();

    $query = Entry::find()->sectionId($sectionModel->id)->bouncer(true);
    $plugin->queryFilter->handleBeforePrepare($query);

    $count = (int)$query->count();

    return $count === 0 ?: "expected 0, got $count";
});

check('the exclusion is scoped to the rule’s sources, not to everything', function() use ($plugin, $sectionModel, $entryRule) {
    $plugin->access->clearCache();

    // Compared against a section that no *other* rule touches, so a demo rule seeded by
    // `tests/manual/seed-demo.php` cannot make this look like over-broad filtering.
    $unprotectedSectionId = null;

    foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
        if ($candidate->id === $sectionModel->id) {
            continue;
        }

        $covered = false;

        foreach ($plugin->rules->getRulesForElementType(Entry::class) as $rule) {
            if ($rule->target->allSources || in_array($candidate->uid, $rule->target->sourceUids, true)) {
                $covered = true;
                break;
            }
        }

        if (!$covered) {
            $unprotectedSectionId = $candidate->id;
            break;
        }
    }

    if ($unprotectedSectionId === null) {
        return true; // Every other section is protected by something; nothing to compare.
    }

    $before = (int)Entry::find()->sectionId($unprotectedSectionId)->status(null)->count();

    $query = Entry::find()->sectionId($unprotectedSectionId)->status(null)->bouncer(true);
    $plugin->queryFilter->handleBeforePrepare($query);

    $after = (int)$query->count();

    return $after === $before ?: "expected $before, got $after";
});

check('and leaves them alone for somebody who is allowed', function() use ($plugin, $sectionModel, $user) {
    $plugin->access->clearCache();
    $previous = Craft::$app->getUser()->getIdentity();
    Craft::$app->getUser()->setIdentity($user);

    $query = Entry::find()->sectionId($sectionModel->id)->bouncer(true);
    $plugin->queryFilter->handleBeforePrepare($query);
    $count = (int)$query->count();

    Craft::$app->getUser()->setIdentity($previous);
    $plugin->access->clearCache();

    return $count === 3 ?: "expected 3, got $count";
});

check('.bouncer(false) opts a query out again', function() use ($plugin, $sectionModel) {
    $plugin->access->clearCache();

    $query = Entry::find()->sectionId($sectionModel->id)->bouncer(false);
    $plugin->queryFilter->handleBeforePrepare($query);

    return (int)$query->count() === 3;
});

check('a disabled rule stops filtering', function() use ($plugin, $sectionModel, $entryRule) {
    $entryRule->enabled = false;
    $plugin->rules->saveRule($entryRule);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    $plugin->access->clearCache();

    $query = Entry::find()->sectionId($sectionModel->id)->bouncer(true);
    $plugin->queryFilter->handleBeforePrepare($query);
    $count = (int)$query->count();

    $entryRule->enabled = true;
    $plugin->rules->saveRule($entryRule);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    return $count === 3 ?: "expected 3, got $count";
});

check('two rules over the same entry both have to pass', function() use ($plugin, $sectionModel, $entryIds, $user, $makeRule) {
    $second = $makeRule(PREFIX . 'Second', [
        'target' => new RuleTarget([
            'type' => RuleTarget::TYPE_ENTRIES,
            'sourceUids' => [$sectionModel->uid],
        ]),
        'access' => new RuleAccess(['permissions' => ['utility:updates']]),
    ]);

    $plugin->access->clearCache();
    $entry = Entry::find()->id($entryIds[0])->one();
    $verdict = $plugin->access->checkElement($entry, $user);

    $result = $verdict->allowed === false
        && count($verdict->matchedRules) === 2
        && $verdict->reason === Verdict::REASON_PERMISSION;

    $plugin->rules->deleteRule($second);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    $plugin->access->clearCache();

    return $result;
});

// ---------------------------------------------------------------------------------------------
section('Signed file references');
// ---------------------------------------------------------------------------------------------

$asset = craft\elements\Asset::find()->kind('image')->one();

if ($asset === null) {
    echo "  (skipped — no assets on this install)\n";
} else {
    check('a bare guarded URL carries no reference', function() use ($plugin, $asset) {
        $url = $plugin->assets->guardedUrl($asset);

        return !str_contains($url, 'bref=') && str_contains($url, $asset->uid);
    });

    check('a signed URL carries one', function() use ($plugin, $asset) {
        return str_contains($plugin->assets->signedUrl($asset, 300), 'bref=');
    });

    check('a signed reference round-trips', function() use ($plugin, $asset) {
        $url = $plugin->assets->signedUrl($asset, 300);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);

        $decoded = $plugin->assets->decodeReference($params['bref'] ?? null, $asset);

        return $decoded !== null && $decoded['bypass'] === true && $decoded['expiry'] > time();
    });

    check('a tampered reference is rejected', function() use ($plugin, $asset) {
        $url = $plugin->assets->signedUrl($asset, 300);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);

        $tampered = substr($params['bref'], 0, -2) . 'zz';

        return $plugin->assets->decodeReference($tampered, $asset) === null;
    });

    check('an expired reference is rejected', function() use ($plugin, $asset) {
        // Signed with an expiry in the past: a valid signature over a stale claim, which is the
        // case a signature check alone would happily accept.
        $url = $plugin->assets->guardedUrl($asset, null, time() - 10, true);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);

        return $plugin->assets->decodeReference($params['bref'] ?? null, $asset) === null;
    });

    check('a reference for one asset does not work for another', function() use ($plugin, $asset) {
        $other = craft\elements\Asset::find()->id(['not', $asset->id])->one();

        if ($other === null) {
            return true;
        }

        $url = $plugin->assets->signedUrl($asset, 300);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);

        return $plugin->assets->decodeReference($params['bref'] ?? null, $other) === null;
    });

    check('a transform survives the round trip', function() use ($plugin, $asset) {
        $url = $plugin->assets->guardedUrl($asset, ['width' => 120, 'height' => 80, 'mode' => 'crop']);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);

        $decoded = $plugin->assets->decodeReference($params['bref'] ?? null, $asset);

        return $decoded !== null
            && $decoded['transform'] !== null
            && (int)$decoded['transform']->width === 120
            && (int)$decoded['transform']->height === 80;
    });

    check('files that could run script are never sent inline', function() use ($plugin) {
        $svg = new craft\elements\Asset(['filename' => 'logo.svg']);
        $pdf = new craft\elements\Asset(['filename' => 'contract.pdf']);

        return $plugin->files->allowsInline($svg) === false && $plugin->files->allowsInline($pdf) === true;
    });
}

// ---------------------------------------------------------------------------------------------
section('File exposure audit');
// ---------------------------------------------------------------------------------------------

$volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;

if ($volume === null) {
    echo "  (skipped — no volumes on this install)\n";
} else {
    $volumeRule = $makeRule(PREFIX . 'Volume', [
        'target' => new RuleTarget([
            'type' => RuleTarget::TYPE_ASSETS,
            'sourceUids' => [$volume->uid],
        ]),
        'access' => new RuleAccess(['requireLogin' => true]),
    ]);

    check('the audit covers a volume a rule protects', function() use ($plugin, $volume) {
        foreach ($plugin->exposure->audit() as $report) {
            if ($report->volume->uid === $volume->uid) {
                return true;
            }
        }

        return 'volume not audited';
    });

    check('a public filesystem is reported as critical', function() use ($plugin, $volume) {
        $fs = $volume->getFs();

        if (!$fs->hasUrls) {
            return true; // Already private; nothing to assert.
        }

        foreach ($plugin->exposure->audit() as $report) {
            if ($report->volume->uid === $volume->uid) {
                return $report->getIsCritical() === true;
            }
        }

        return 'volume not audited';
    });

    check('an exposed local path produces a server snippet', function() use ($plugin, $volume) {
        foreach ($plugin->exposure->audit() as $report) {
            if ($report->volume->uid !== $volume->uid) {
                continue;
            }

            if ($report->exposedPaths === []) {
                return true; // Not under the web root; nothing to generate.
            }

            $apache = $plugin->exposure->serverSnippet($report, 'apache');
            $nginx = $plugin->exposure->serverSnippet($report, 'nginx');

            return str_contains((string)$apache, 'Require all denied') && str_contains((string)$nginx, 'deny all');
        }

        return 'volume not audited';
    });

    check('an assets rule is unevaluable on Lite', function() use ($plugin, $setEdition, $volumeRule) {
        $setEdition(Plugin::EDITION_LITE);

        $evaluable = $volumeRule->isEvaluableBy(false);

        $setEdition(Plugin::EDITION_PRO);

        return $evaluable === false;
    });
}

// ---------------------------------------------------------------------------------------------
section('Cleaning up');
// ---------------------------------------------------------------------------------------------

foreach ($entryIds as $id) {
    $entry = Entry::find()->id($id)->status(null)->one();

    if ($entry !== null) {
        Craft::$app->getElements()->deleteElement($entry, true);
    }
}

$cleanup();
Craft::$app->getPlugins()->switchEdition('bouncer', $originalEdition);

check('no check rules are left behind', function() use ($plugin) {
    foreach ($plugin->rules->getAllRules() as $rule) {
        if (str_starts_with($rule->handle, PREFIX)) {
            return "left behind: {$rule->handle}";
        }
    }

    return true;
});

check('no check sections are left behind', function() {
    foreach (Craft::$app->getEntries()->getAllSections() as $sectionModel) {
        if (str_starts_with($sectionModel->handle, PREFIX)) {
            return "left behind: {$sectionModel->handle}";
        }
    }

    return true;
});

echo "\n----------------------------------------\n";
echo "$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
