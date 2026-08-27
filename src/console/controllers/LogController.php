<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bouncer\Plugin;
use yii\console\ExitCode;

/**
 * `craft bouncer/log/…` — maintenance for the access log.
 *
 * Craft's garbage collection already prunes to the retention setting; these exist for the times
 * somebody wants it done now, or wants the table empty before handing a database over.
 */
class LogController extends Controller
{
    public $defaultAction = 'prune';

    public function actionPrune(?int $days = null): int
    {
        $removed = Plugin::getInstance()->log->prune($days);

        $this->stdout("Removed $removed log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionClear(): int
    {
        if ($this->interactive && !$this->confirm('Delete every access log entry?')) {
            return ExitCode::OK;
        }

        $removed = Plugin::getInstance()->log->clear();

        $this->stdout("Removed $removed log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
