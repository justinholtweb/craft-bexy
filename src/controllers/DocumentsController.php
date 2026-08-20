<?php

namespace justinholtweb\bexy\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Documents section, and the actions on Commerce's order screen.
 */
class DocumentsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('bexy-viewDocuments');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $documents = Plugin::getInstance()->getDocuments();

        $criteria = [
            'status' => $request->getParam('status') ?: null,
            'type' => $request->getParam('type') ?: null,
            'needsAttention' => (bool)$request->getParam('attention'),
            'search' => $request->getParam('search') ?: null,
        ];

        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = 50;

        return $this->renderTemplate('bexy/documents/_index', [
            'documents' => $documents->getDocuments($criteria, $perPage, ($page - 1) * $perPage),
            'counts' => $documents->getCounts(),
            'criteria' => $criteria,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $documents->getTotal($criteria),
            'connected' => Plugin::getInstance()->getAuth()->isConnected(),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $documentId): Response
    {
        $document = Plugin::getInstance()->getDocuments()->getDocument($documentId);

        if (!$document) {
            throw new NotFoundHttpException('Document not found');
        }

        return $this->renderTemplate('bexy/documents/_detail', [
            'document' => $document,
            'order' => $document->getOrder(),
            'payments' => Plugin::getInstance()->getPayments()->getPaymentsForDocument($document->id),
            'canPush' => Craft::$app->getUser()->checkPermission('bexy-pushDocuments'),
            'canCancel' => Craft::$app->getUser()->checkPermission('bexy-cancelDocuments'),
        ]);
    }

    /**
     * Push an order now, synchronously, so the merchant sees the result rather than a queue job.
     */
    public function actionPush(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('bexy-pushDocuments');

        $order = $this->orderFromRequest();
        $force = (bool)Craft::$app->getRequest()->getBodyParam('force');

        try {
            $document = Plugin::getInstance()->getDocuments()->push($order, $force);
        } catch (BexioApiException $e) {
            $message = Plugin::getInstance()->getApi()->explain($e);

            if ($this->request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'message' => $message]);
            }

            Craft::$app->getSession()->setError($message);

            return $this->redirectToPostedUrl();
        } catch (Throwable $e) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
            }

            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirectToPostedUrl();
        }

        $message = Craft::t('bexy', 'Sent to bexio as {number}.', [
            'number' => $document->bexioNumber ?: $document->bexioId,
        ]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'message' => $message,
                'documentId' => $document->id,
                'bexioUrl' => $document->getBexioUrl(),
            ]);
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirectToPostedUrl();
    }

    /**
     * Hand the push to the queue instead of waiting for it. Used for bulk actions.
     */
    public function actionQueue(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('bexy-pushDocuments');

        $order = $this->orderFromRequest();
        Plugin::getInstance()->queueSync($order, (bool)Craft::$app->getRequest()->getBodyParam('force'));

        Craft::$app->getSession()->setNotice(Craft::t('bexy', 'Queued for bexio.'));

        return $this->redirectToPostedUrl();
    }

    public function actionReconcile(): ?Response
    {
        $this->requirePostRequest();

        $document = $this->documentFromRequest();

        try {
            Plugin::getInstance()->getReconcile()->reconcile($document);
        } catch (BexioApiException $e) {
            Craft::$app->getSession()->setError(Plugin::getInstance()->getApi()->explain($e));

            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('bexy', 'Refreshed from bexio.'));

        return $this->redirectToPostedUrl();
    }

    public function actionCancel(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('bexy-cancelDocuments');

        $document = $this->documentFromRequest();

        try {
            Plugin::getInstance()->getDocuments()->cancel($document);
        } catch (BexioApiException $e) {
            Craft::$app->getSession()->setError(Plugin::getInstance()->getApi()->explain($e));

            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('bexy', 'Cancelled in bexio.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Show the exact JSON that would be sent, without sending it.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $order = $this->orderFromRequest();
        $contactId = null;

        try {
            $contactId = Plugin::getInstance()->getContacts()->getMappedContactId((string)$order->getEmail());
            $payload = Plugin::getInstance()->getBuilder()->forOrder($order, $contactId);
        } catch (Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->asJson([
            'success' => true,
            'json' => $payload->toJson(),
            'warnings' => $payload->warnings,
            'orderTotal' => $payload->orderTotal,
            'documentTotal' => $payload->documentTotal,
            'delta' => $payload->delta,
        ]);
    }

    /**
     * Stream bexio's own PDF of the document.
     */
    public function actionPdf(int $documentId): Response
    {
        $document = Plugin::getInstance()->getDocuments()->getDocument($documentId);

        if (!$document) {
            throw new NotFoundHttpException('Document not found');
        }

        try {
            $pdf = Plugin::getInstance()->getDocuments()->getPdf($document);
        } catch (BexioApiException $e) {
            throw new NotFoundHttpException(Plugin::getInstance()->getApi()->explain($e));
        }

        if (!$pdf) {
            throw new NotFoundHttpException('bexio returned no PDF for this document');
        }

        return Craft::$app->getResponse()->sendContentAsFile(
            $pdf['content'],
            $pdf['name'],
            ['mimeType' => $pdf['mime'], 'inline' => true],
        );
    }

    /**
     * Drop Bexy's record. The bexio document stays where it is.
     */
    public function actionForget(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('bexy-cancelDocuments');

        $document = $this->documentFromRequest();
        Plugin::getInstance()->getDocuments()->forget($document->id);

        Craft::$app->getSession()->setNotice(Craft::t('bexy', 'Bexy has forgotten this order. The bexio document was left alone.'));

        return $this->redirect(UrlHelper::cpUrl('bexy/documents'));
    }

    /**
     * @throws NotFoundHttpException
     */
    private function orderFromRequest(): Order
    {
        $orderId = (int)Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found');
        }

        return $order;
    }

    /**
     * @throws NotFoundHttpException
     */
    private function documentFromRequest(): Document
    {
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('documentId');
        $document = Plugin::getInstance()->getDocuments()->getDocument($id);

        if (!$document) {
            throw new NotFoundHttpException('Document not found');
        }

        return $document;
    }
}
