<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bouncer\Plugin;
use yii\console\ExitCode;

/**
 * `craft bouncer/rules/…` — reading and testing rules from the command line.
 */
class RulesController extends Controller
{
    /** Evaluate as this user, by ID. Omitted means an anonymous visitor. */
    public ?string $userId = null;

    public $defaultAction = 'list';

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'test') {
            $options[] = 'userId';
        }

        return $options;
    }

    public function actionList(): int
    {
        $rules = Plugin::getInstance()->rules->getAllRules();

        if ($rules === []) {
            $this->stdout("No rules.\n");

            return ExitCode::OK;
        }

        foreach ($rules as $rule) {
            $this->stdout(str_pad($rule->handle, 30), Console::BOLD);
            $this->stdout(str_pad($rule->target->type, 12));
            $this->stdout($rule->enabled ? 'enabled' : 'disabled', $rule->enabled ? Console::FG_GREEN : Console::FG_GREY);
            $this->stdout("  → {$rule->response->type}\n");
        }

        return ExitCode::OK;
    }

    /**
     * Ask what a given user would get for a given element or URI.
     *
     * The command form of the CP tester, and the one that belongs in a deploy check: "does an
     * anonymous visitor still get the members index" is a question worth asking automatically.
     *
     * @param string $subject An element ID, or a URI.
     */
    public function actionTest(string $subject): int
    {
        $plugin = Plugin::getInstance();
        $user = $this->userId ? Craft::$app->getUsers()->getUserById((int)$this->userId) : null;

        if ($this->userId && $user === null) {
            $this->stderr("No user with ID {$this->userId}.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if (ctype_digit($subject)) {
            $element = Craft::$app->getElements()->getElementById((int)$subject);

            if ($element === null) {
                $this->stderr("No element with ID $subject.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $verdict = $plugin->access->checkElement($element, $user);
        } else {
            $verdict = $plugin->access->checkUri($subject, $user);
        }

        $who = $user?->username ?? 'an anonymous visitor';

        if ($verdict->allowed) {
            $suffix = $verdict->getIsProtected() ? ' (protected, and they pass)' : ' (nothing protects it)';
            $this->stdout("Allowed for $who$suffix.\n", Console::FG_GREEN);
        } else {
            $this->stdout("Refused for $who: {$verdict->reason}, by “{$verdict->rule?->handle}”.\n", Console::FG_RED);
        }

        if ($verdict->matchedRules !== []) {
            $handles = implode(', ', array_map(static fn($r) => $r->handle, $verdict->matchedRules));
            $this->stdout("Matched rules: $handles\n");
        }

        return ExitCode::OK;
    }
}
