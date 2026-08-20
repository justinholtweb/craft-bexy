<?php

namespace justinholtweb\bexy\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\bexy\Plugin;
use Throwable;
use yii\web\Response;

/**
 * Where bexio sends the browser back to.
 */
class OAuthController extends Controller
{
    /**
     * @inheritdoc
     *
     * bexio redirects the browser here with the code in the query string, so this one action is
     * a plain GET with no CSRF token of ours on it. The `state` parameter is what stands in for
     * one, and `Auth::handleCallback()` refuses anything that does not match the session.
     */
    protected array|int|bool $allowAnonymous = false;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requireAdmin();

        return parent::beforeAction($action);
    }

    public function actionCallback(): Response
    {
        $request = Craft::$app->getRequest();
        $settingsUrl = UrlHelper::cpUrl('settings/plugins/bexy');
        $session = Craft::$app->getSession();

        $error = $request->getQueryParam('error');

        if ($error) {
            $session->setError(Craft::t('bexy', 'bexio refused the connection: {error}', [
                'error' => (string)($request->getQueryParam('error_description') ?: $error),
            ]));

            return $this->redirect($settingsUrl);
        }

        $code = (string)$request->getQueryParam('code');
        $state = (string)$request->getQueryParam('state');

        if ($code === '') {
            $session->setError(Craft::t('bexy', 'bexio sent no authorization code back.'));

            return $this->redirect($settingsUrl);
        }

        try {
            Plugin::getInstance()->getAuth()->handleCallback($code, $state);
            Plugin::getInstance()->getMeta()->flush();
        } catch (Throwable $e) {
            $session->setError($e->getMessage());

            return $this->redirect($settingsUrl);
        }

        $session->setNotice(Craft::t('bexy', 'Connected to bexio.'));

        return $this->redirect($settingsUrl);
    }
}
