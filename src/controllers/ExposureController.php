<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The file exposure screen (Pro).
 *
 * Separate from the rules screen because it answers a different question. The rules screen says
 * what you meant; this one says whether the server agrees.
 */
class ExposureController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        if (!Edition::allowsAssetProtection(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException(Craft::t('bouncer', 'File protection needs Bouncer Pro.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $exposure = Plugin::getInstance()->exposure;
        $reports = $exposure->audit();
        $snippets = [];

        foreach ($reports as $index => $report) {
            $snippets[$index] = [
                'apache' => $exposure->serverSnippet($report, 'apache'),
                'nginx' => $exposure->serverSnippet($report, 'nginx'),
            ];
        }

        return $this->renderTemplate('bouncer/_exposure/index', [
            'reports' => $reports,
            'snippets' => $snippets,
        ]);
    }
}
