<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bouncer\Plugin;
use yii\console\ExitCode;

/**
 * `craft bouncer/files/…` — the generated protected transforms.
 */
class FilesController extends Controller
{
    public $defaultAction = 'clear-transforms';

    /**
     * Delete every transform Bouncer generated for a protected asset.
     *
     * They live under Craft's runtime path rather than in the volume's transform filesystem — see
     * `services\Files` for why — so `clear-caches` does not touch them, and neither does deleting
     * the volume's transforms in the control panel.
     */
    public function actionClearTransforms(): int
    {
        Plugin::getInstance()->files->clearTransforms();

        $this->stdout("Protected transforms cleared.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
