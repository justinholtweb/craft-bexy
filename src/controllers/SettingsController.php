<?php

namespace justinholtweb\bexy\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\bexy\Plugin;
use yii\web\Response;

/**
 * The settings screen's buttons: connect, disconnect, test, refresh.
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requireAdmin();

        return parent::beforeAction($action);
    }

    /**
     * Send the merchant to bexio to authorise the connection.
     */
    public function actionConnect(): Response
    {
        $this->requirePostRequest();

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->getClientId() === '' || $settings->getClientSecret() === '') {
            Craft::$app->getSession()->setError(Craft::t('bexy', 'Save a client ID and secret first.'));

            return $this->redirectToPostedUrl();
        }

        return $this->redirect(Plugin::getInstance()->getAuth()->getAuthorizationUrl());
    }

    public function actionDisconnect(): ?Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getAuth()->disconnect();
        Plugin::getInstance()->getMeta()->flush();

        Craft::$app->getSession()->setNotice(Craft::t('bexy', 'Disconnected from bexio.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * A live round-trip, so "it's configured" and "it works" are not the same claim.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $result = Plugin::getInstance()->getApi()->testConnection();

        return $this->asJson($result);
    }

    /**
     * Drop the cached lookup tables, for when the merchant has just added the tax rate they are
     * trying to map and cannot see it yet.
     */
    public function actionRefreshMeta(): ?Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getMeta()->flush();
        Plugin::getInstance()->getArticles()->flush();

        Craft::$app->getSession()->setNotice(Craft::t('bexy', 'Refreshed from bexio.'));

        return $this->redirectToPostedUrl();
    }
}
