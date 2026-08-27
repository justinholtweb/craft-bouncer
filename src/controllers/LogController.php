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
 * The access log screen (Pro).
 */
class LogController extends Controller
{
    private const PAGE_SIZE = 100;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        if (!Edition::allowsAccessLog(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException(Craft::t('bouncer', 'The access log needs Bouncer Pro.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $outcome = $request->getQueryParam('outcome');
        $page = max(1, (int)$request->getQueryParam('page', 1));

        $query = Plugin::getInstance()->log->find(['outcome' => $outcome]);
        $total = (int)$query->count();

        $rows = $query
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->all();

        return $this->renderTemplate('bouncer/_log/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'outcome' => $outcome,
            'enabled' => Plugin::getInstance()->log->isEnabled(),
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $removed = Plugin::getInstance()->log->clear();

        Craft::$app->getSession()->setNotice(Craft::t('bouncer', '{count} log entries removed.', ['count' => $removed]));

        return $this->redirect('bouncer/log');
    }
}
