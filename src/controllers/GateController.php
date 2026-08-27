<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The password gate's front-end endpoints (Pro).
 *
 * Small on purpose. The form posts here, the unlock goes in the session, and the visitor is sent
 * back to where they came from — with the redirect taken from Craft's *hashed* redirect input, so
 * this cannot be turned into an open redirect by anybody who can write a link.
 */
class GateController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionSubmit(): ?Response
    {
        $this->requirePostRequest();

        if (!Edition::allowsPassword(Plugin::getInstance()->isPro())) {
            throw new BadRequestHttpException();
        }

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('rule');
        $password = (string)$request->getBodyParam('password', '');

        $rule = Plugin::getInstance()->rules->getRuleByHandle($handle);

        if ($rule === null || !$rule->enabled || !$rule->access->getHasPassword()) {
            throw new BadRequestHttpException();
        }

        $gate = Plugin::getInstance()->gate;

        if ($gate->isThrottled($rule)) {
            Craft::$app->getSession()->setError(Craft::t('bouncer', 'Too many attempts. Try again later.'));

            return $this->redirectBack();
        }

        if (!$gate->attempt($rule, $password)) {
            Craft::$app->getSession()->setError(Craft::t('bouncer', 'That password is not right.'));

            return $this->redirectBack();
        }

        Craft::$app->getSession()->setNotice(Craft::t('bouncer', 'Unlocked.'));

        return $this->redirectBack();
    }

    /** Forget every password unlock in this session. The "log out" of the password gate. */
    public function actionLock(): ?Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->gate->lockAll();

        return $this->redirectBack();
    }

    private function redirectBack(): ?Response
    {
        // Craft only honours a redirect input that was hashed with the site's security key, so a
        // hand-written form cannot send visitors off-site. The referrer fallback is
        // same-origin-checked for the same reason. `redirectToPostedUrl()` is deliberately not
        // used: its own fallback is the *action* path, which would bounce the visitor to a URL
        // that renders nothing.
        $posted = $this->getPostedRedirectUrl();

        if ($posted !== null) {
            return $this->redirect($posted);
        }

        $referrer = Craft::$app->getRequest()->getReferrer();

        if ($referrer !== null && str_starts_with($referrer, Craft::$app->getSites()->getCurrentSite()->getBaseUrl() ?? '')) {
            return $this->redirect($referrer);
        }

        return $this->redirect(Craft::$app->getSites()->getCurrentSite()->getBaseUrl() ?? '/');
    }
}
