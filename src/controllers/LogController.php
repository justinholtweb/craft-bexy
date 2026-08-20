<?php

namespace justinholtweb\bexy\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\bexy\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log.
 */
class LogController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('bexy-viewLog');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $log = Plugin::getInstance()->getLog();

        $criteria = [
            'level' => $request->getParam('level') ?: null,
            'action' => $request->getParam('action') ?: null,
            'search' => $request->getParam('search') ?: null,
        ];

        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = 100;

        return $this->renderTemplate('bexy/log/_index', [
            'entries' => $log->getEntries($criteria, $perPage, ($page - 1) * $perPage),
            'actions' => $log->getActions(),
            'criteria' => $criteria,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $log->getTotal($criteria),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntry($entryId);

        if (!$entry) {
            throw new NotFoundHttpException('Log entry not found');
        }

        return $this->renderTemplate('bexy/log/_detail', [
            'entry' => $entry,
        ]);
    }

    public function actionClear(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        Plugin::getInstance()->getLog()->clear();

        Craft::$app->getSession()->setNotice(Craft::t('bexy', 'Log cleared.'));

        return $this->redirectToPostedUrl();
    }
}
