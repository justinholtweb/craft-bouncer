<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\View;
use justinholtweb\bouncer\models\RuleResponse;
use justinholtweb\bouncer\models\Verdict;
use justinholtweb\bouncer\Plugin;
use yii\base\ActionEvent;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Enforcement at the front door: the page request itself.
 *
 * Hooked on `Controller::EVENT_BEFORE_ACTION`, which is the first point at which routing has
 * finished — so `UrlManager::getMatchedElement()` is populated and the entry behind the URL is
 * known — and still early enough that no template has begun rendering.
 *
 * Everything this skips is listed in {@see self::shouldGuard()} and every entry there is
 * deliberate. The two that matter most: **previews are never guarded** (an author previewing a
 * members-only entry is not the public, and gating it makes the plugin look broken), and
 * **Bouncer's own routes are never guarded** (the password form lives at the URL it protects).
 */
class Guard extends Component
{
    public function handleBeforeAction(ActionEvent $event): void
    {
        if (!$this->shouldGuard($event)) {
            return;
        }

        $request = Craft::$app->getRequest();
        $element = Craft::$app->getUrlManager()->getMatchedElement();

        $verdict = $element instanceof ElementInterface
            ? Plugin::getInstance()->access->checkElement($element)
            : Plugin::getInstance()->access->checkUri($request->getPathInfo());

        Plugin::getInstance()->log->recordVerdict($verdict, $element ?: null, $request->getPathInfo());

        if ($verdict->allowed) {
            return;
        }

        $event->isValid = false;

        $this->respond($verdict, $element ?: null);
    }

    private function shouldGuard(ActionEvent $event): bool
    {
        if (!Plugin::getInstance()->getSettings()->enforceRequests) {
            return false;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest || !$request->getIsSiteRequest()) {
            return false;
        }

        // Action requests are POSTs and Ajax calls, not pages. Guarding them here would refuse a
        // login form submitted *from* a protected page, which is exactly backwards.
        if ($request->getIsActionRequest()) {
            return false;
        }

        // Previews and share tokens are Craft's own way of showing unpublished content to someone
        // who has been given a link. Both are already access-controlled by Craft.
        if ($request->getIsPreview() || $request->getIsLivePreview() || $request->getToken() !== null) {
            return false;
        }

        // Never guard Bouncer's own front-end routes: the file route does its own checking, and
        // the password form is rendered *at* the protected URL, so guarding it would loop.
        $controller = $event->action->controller;

        if (str_starts_with($controller->module?->id ?? '', 'bouncer') || $controller->id === 'bouncer') {
            return false;
        }

        if ($controller instanceof \justinholtweb\bouncer\controllers\GateController
            || $controller instanceof \justinholtweb\bouncer\controllers\FileController) {
            return false;
        }

        // Error pages. Guarding the 404 template turns a missing page into a redirect loop.
        if (Craft::$app->getErrorHandler()->exception !== null) {
            return false;
        }

        return true;
    }

    /**
     * Emit the refusal.
     *
     * Everything here writes to the already-created response rather than sending and exiting.
     * `Craft::$app->end()` would skip `EVENT_AFTER_REQUEST` and every other plugin's response
     * handling, and the difference only shows up on somebody else's site.
     */
    private function respond(Verdict $verdict, ?ElementInterface $element): void
    {
        $ruleResponse = $verdict->getResponse() ?? new RuleResponse();
        $response = Craft::$app->getResponse();

        switch ($ruleResponse->type) {
            case RuleResponse::TYPE_NOT_FOUND:
                throw new NotFoundHttpException();

            case RuleResponse::TYPE_FORBIDDEN:
                throw new ForbiddenHttpException($ruleResponse->message ?? Craft::t('bouncer', 'You do not have access to this page.'));

            case RuleResponse::TYPE_REDIRECT:
                $this->flashMessage($ruleResponse);
                $response->redirect(UrlHelper::siteUrl(Craft::getAlias($ruleResponse->redirectUrl ?? '/')));
                return;

            case RuleResponse::TYPE_TEMPLATE:
                $this->render($response, (string)$ruleResponse->template, $verdict, $element);
                return;

            case RuleResponse::TYPE_PASSWORD:
                $this->render(
                    $response,
                    Plugin::getInstance()->getSettings()->passwordTemplate ?: 'bouncer/_gate/password',
                    $verdict,
                    $element,
                    Plugin::getInstance()->getSettings()->passwordTemplate === null,
                );
                return;

            case RuleResponse::TYPE_LOGIN:
            default:
                $this->sendToLogin($ruleResponse, $verdict, $element, $response);
        }
    }

    private function sendToLogin(RuleResponse $ruleResponse, Verdict $verdict, ?ElementInterface $element, WebResponse $response): void
    {
        $request = Craft::$app->getRequest();

        // Craft's own return-URL mechanism, so the user lands back here after logging in — the
        // single thing that makes a login redirect tolerable rather than infuriating.
        Craft::$app->getUser()->setReturnUrl($request->getAbsoluteUrl());

        // Rendering in place only works if the site actually has a `login` template. Craft's
        // login path is a *route*, not necessarily a template of that name, so this checks rather
        // than assuming — a missing template here would turn a refusal into a 500.
        if ($ruleResponse->preserveUrl && Craft::$app->getView()->doesTemplateExist('login', View::TEMPLATE_MODE_SITE)) {
            $this->render($response, 'login', $verdict, $element);
            return;
        }

        $this->flashMessage($ruleResponse);

        $loginPath = Craft::$app->getConfig()->getGeneral()->getLoginPath();

        $response->redirect(UrlHelper::siteUrl(is_string($loginPath) ? $loginPath : 'login'));
    }

    private function flashMessage(RuleResponse $ruleResponse): void
    {
        if ($ruleResponse->message !== null && $ruleResponse->message !== '') {
            Craft::$app->getSession()->setError($ruleResponse->message);
        }
    }

    /**
     * Render a template in place, keeping the URL and returning a refusal status code.
     *
     * The status code matters more than it looks: a paywall page returned as 200 gets indexed by
     * search engines as the content it is hiding.
     */
    private function render(
        WebResponse $response,
        string $template,
        Verdict $verdict,
        ?ElementInterface $element,
        bool $pluginTemplate = false,
    ): void {
        $view = Craft::$app->getView();
        $variables = [
            'verdict' => $verdict,
            'rule' => $verdict->rule,
            'reason' => $verdict->reason,
            'message' => $verdict->getResponse()?->message,
            'element' => $element,
            'entry' => $element,
        ];

        if ($pluginTemplate) {
            $mode = $view->getTemplateMode();
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            $html = $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
            $view->setTemplateMode($mode);
        } else {
            $html = $view->renderPageTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        }

        $response->format = WebResponse::FORMAT_RAW;
        $response->data = $html;
        $response->setStatusCode($verdict->getResponse()?->getStatusCode() ?? 403);
        $response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
    }
}
