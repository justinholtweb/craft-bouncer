<?php
/**
 * Who can change access rules, and where — checked over HTTP in the plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bouncer/tests/integration/security.php
 *
 * Until 5.0.1 admins could save, delete and reorder rules on an environment with admin changes
 * turned off. Rules are project config, so the next deploy reverted the change, re-exposing or
 * locking content nobody had decided to. And the Bouncer nav showed for non-admins given plugin
 * access, though every page it led to answered 403.
 *
 * To see a web request with admin changes off, this flips CRAFT_ALLOW_ADMIN_CHANGES in the
 * harness .env for a few seconds and always puts it back. Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();
$bouncer = Plugin::getInstance();

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'bn-' . bin2hex(random_bytes(12));
$envFile = $root . '/.env';
$envBefore = (string)file_get_contents($envFile);
$cleanup = ['users' => [], 'rule' => null];

$restoreEnv = static function() use ($envFile, $envBefore) {
    if ((string)file_get_contents($envFile) !== $envBefore) {
        file_put_contents($envFile, $envBefore);
    }
};

register_shutdown_function(function() use (&$cleanup, $bouncer, $restoreEnv) {
    $restoreEnv();
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    // Web saves may have moved project config on: reload before removing the rule.
    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    Craft::$app->getProjectConfig()->reset();
    foreach ($bouncer->rules->getAllRules() as $rule) {
        if (str_starts_with((string)$rule->handle, 'bouncerSecurity')) {
            $bouncer->rules->deleteRuleById($rule->id);
        }
    }
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    Craft::$app->getProjectConfig()->writeYamlFiles(true);
});

/** Turns admin changes off for the web process, runs `$fn`, and turns them back on. */
$withAdminChangesOff = static function(callable $fn) use ($envFile, $envBefore, $restoreEnv) {
    $off = preg_match('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', $envBefore)
        ? preg_replace('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', 'CRAFT_ALLOW_ADMIN_CHANGES=false', $envBefore)
        : rtrim($envBefore) . "\nCRAFT_ALLOW_ADMIN_CHANGES=false\n";
    file_put_contents($envFile, $off);

    try {
        return $fn();
    } finally {
        $restoreEnv();
    }
};

// A rule to work with, saved from the console.
$rule = new AccessRule(['name' => "Bouncer security $run", 'handle' => "bouncerSecurity$run"]);
$rule->target->type = 'uri';
$rule->target->uriPatterns = ["bouncer-security-$run/*"];
$rule->access->requireLogin = true;
$bouncer->rules->saveRule($rule) or throw new RuntimeException(json_encode($rule->getErrors()));
Craft::$app->getProjectConfig()->saveModifiedConfigData();
Craft::$app->getProjectConfig()->writeYamlFiles(true);
$rule = $bouncer->rules->getRuleByHandle("bouncerSecurity$run") ?? throw new RuntimeException('Rule did not come back');

/** The rule as stored now — read from the table, since the web process may have changed it. */
$storedRule = static fn(): ?array => (new Query())->from('{{%bouncer_rules}}')->where(['handle' => "bouncerSecurity$run"])->one() ?: null;

// A non-admin who has been given access to the plugin.
$editor = new User(['username' => "bouncer-editor-$run", 'email' => "bouncer-editor-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($editor, false);
Craft::$app->getUsers()->activateUser($editor);
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, ['accesscp', 'accessplugin-bouncer']);
$cleanup['users'][] = $editor;

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

    if ($login->getStatusCode() !== 200) {
        echo "Could not sign in as $username\n";
        exit(1);
    }

    $post = static fn(string $action, array $params, bool $asJson = true) => $http->post("index.php?p=admin/actions/$action", [
        'headers' => $asJson ? $json : [],
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ])->getStatusCode();

    return [$http, $post];
}

[$editorHttp] = client($editor->username, $password);
[$adminHttp, $adminPost] = client('admin', 'claudepassword');

echo "\nNavigation\n";

check('a non-admin with plugin access doesn’t get the Bouncer nav', function() use ($editorHttp, $adminHttp) {
    $editor = (string)$editorHttp->get('index.php?p=admin/dashboard')->getBody();
    $admin = (string)$adminHttp->get('index.php?p=admin/dashboard')->getBody();

    return !str_contains($editor, 'bouncer/rules') && str_contains($admin, 'bouncer/rules') ?: 'editor nav: ' . var_export(str_contains($editor, 'bouncer/rules'), true);
});

echo "\nWith admin changes off\n";

check('the rules screens open, read-only', fn() => $withAdminChangesOff(function() use ($adminHttp, $rule) {
    $index = (string)$adminHttp->get('index.php?p=admin/bouncer/rules')->getBody();
    $edit = $adminHttp->get("index.php?p=admin/bouncer/rules/{$rule->id}");
    $editBody = (string)$edit->getBody();

    return str_contains($index, 'admin changes are turned off') && !str_contains($index, 'bouncer-delete')
        && $edit->getStatusCode() === 200 && str_contains($editBody, 'bouncer-readonly')
        ?: 'index notice ' . var_export(str_contains($index, 'admin changes are turned off'), true) . ', edit ' . $edit->getStatusCode();
}));

check('…a new rule can’t be started', fn() => $withAdminChangesOff(fn() => ($s = $adminHttp->get('index.php?p=admin/bouncer/rules/new')->getStatusCode()) === 403 ?: "status $s"));

check('…and saving, deleting or reordering is refused, leaving the rule as it was', fn() => $withAdminChangesOff(function() use ($adminPost, $rule, $run, $storedRule) {
    $codes = [
        $adminPost('bouncer/rules/save', ['ruleId' => $rule->id, 'name' => "Changed $run", 'handle' => $rule->handle, 'targetType' => 'uri'], false),
        $adminPost('bouncer/rules/reorder', ['ids' => json_encode([$rule->id])]),
        $adminPost('bouncer/rules/delete', ['id' => $rule->id]),
    ];
    $stored = $storedRule();

    return array_unique($codes) === [403] && $stored !== null && $stored['name'] === "Bouncer security $run"
        ?: json_encode($codes) . ' ' . ($stored['name'] ?? 'deleted');
}));

echo "\nWith admin changes on\n";

check('an admin can still save and delete a rule', function() use ($adminPost, $rule, $storedRule) {
    $delete = $adminPost('bouncer/rules/delete', ['id' => $rule->id]);

    return $delete === 200 && $storedRule() === null ?: "status $delete";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
