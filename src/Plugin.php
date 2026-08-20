<?php

namespace justinholtweb\bexy;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\services\OrderHistories;
use craft\commerce\events\OrderStatusEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\bexy\models\Settings;
use justinholtweb\bexy\queue\jobs\SyncOrderJob;
use justinholtweb\bexy\services\Api;
use justinholtweb\bexy\services\Articles;
use justinholtweb\bexy\services\Auth;
use justinholtweb\bexy\services\Builder;
use justinholtweb\bexy\services\Contacts;
use justinholtweb\bexy\services\Documents;
use justinholtweb\bexy\services\Log;
use justinholtweb\bexy\services\Meta;
use justinholtweb\bexy\services\Payments;
use justinholtweb\bexy\services\Reconcile;
use justinholtweb\bexy\twig\BexyVariable;
use yii\base\Event;

/**
 * Bexy — bexio for Craft Commerce.
 *
 * @property-read Api $api
 * @property-read Articles $articles
 * @property-read Auth $auth
 * @property-read Builder $builder
 * @property-read Contacts $contacts
 * @property-read Documents $documents
 * @property-read Log $log
 * @property-read Meta $meta
 * @property-read Payments $payments
 * @property-read Reconcile $reconcile
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'bexy';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'api' => ['class' => Api::class],
                'articles' => ['class' => Articles::class],
                'auth' => ['class' => Auth::class],
                'builder' => ['class' => Builder::class],
                'contacts' => ['class' => Contacts::class],
                'documents' => ['class' => Documents::class],
                'log' => ['class' => Log::class],
                'meta' => ['class' => Meta::class],
                'payments' => ['class' => Payments::class],
                'reconcile' => ['class' => Reconcile::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'justinholtweb\\bexy\\console\\controllers';
        }

        // Bexy can be installed while Commerce is disabled, or mid-upgrade. Everything below
        // touches an order, so it all waits until Commerce is actually there.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEvents();
        $this->_registerOrderEditPanel();
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getArticles(): Articles
    {
        return $this->get('articles');
    }

    public function getAuth(): Auth
    {
        return $this->get('auth');
    }

    public function getBuilder(): Builder
    {
        return $this->get('builder');
    }

    public function getContacts(): Contacts
    {
        return $this->get('contacts');
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getMeta(): Meta
    {
        return $this->get('meta');
    }

    public function getPayments(): Payments
    {
        return $this->get('payments');
    }

    public function getReconcile(): Reconcile
    {
        return $this->get('reconcile');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('bexy/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('bexy', 'Bexy');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('bexy-viewDocuments')) {
            $subNav['documents'] = [
                'label' => Craft::t('bexy', 'Documents'),
                'url' => 'bexy/documents',
            ];
        }

        if ($user->checkPermission('bexy-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('bexy', 'Log'),
                'url' => 'bexy/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('bexy', 'Settings'),
                'url' => 'settings/plugins/bexy',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('bexy', BexyVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('bexy', 'Bexy'),
                    'permissions' => [
                        'bexy-viewDocuments' => [
                            'label' => Craft::t('bexy', 'View bexio documents'),
                            'nested' => [
                                'bexy-pushDocuments' => [
                                    'label' => Craft::t('bexy', 'Push orders to bexio'),
                                ],
                                'bexy-cancelDocuments' => [
                                    'label' => Craft::t('bexy', 'Cancel bexio documents'),
                                ],
                            ],
                        ],
                        'bexy-viewLog' => [
                            'label' => Craft::t('bexy', 'View the connection log'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['bexy'] = 'bexy/documents/index';
                $event->rules['bexy/documents'] = 'bexy/documents/index';
                $event->rules['bexy/documents/<documentId:\d+>'] = 'bexy/documents/detail';
                $event->rules['bexy/log'] = 'bexy/log/index';
                $event->rules['bexy/log/<entryId:\d+>'] = 'bexy/log/detail';
                // bexio redirects the browser here after the merchant consents. It has to be a
                // GET route with a stable URL, because it is registered at developer.bexio.com.
                $event->rules['bexy/oauth/callback'] = 'bexy/oauth/callback';
            }
        );
    }

    /**
     * Queue a push when an order completes, and again when it reaches a mapped status.
     */
    private function _registerOrderEvents(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                $settings = $this->getSettings();

                if (!$settings->autoSync) {
                    return;
                }

                // A push triggered by an order status waits for that status instead.
                if ($settings->syncOnStatuses !== []) {
                    $this->getDocuments()->markPending($order);

                    return;
                }

                $this->queueSync($order);
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            function(OrderStatusEvent $event) {
                $settings = $this->getSettings();

                if (!$settings->autoSync || $settings->syncOnStatuses === []) {
                    return;
                }

                // The event carries the order itself; reaching through the history for it
                // reloads the element and loses whatever the current request just changed.
                $order = $event->order;

                if (!$order->isCompleted) {
                    return;
                }

                $handle = $order->getOrderStatus()?->handle;

                if (!$handle || !in_array($handle, $settings->syncOnStatuses, true)) {
                    return;
                }

                $this->queueSync($order);
            }
        );
    }

    /**
     * Note the order and hand the network call to the queue.
     *
     * Nothing here touches bexio. That is the whole point: an accounting outage must never be
     * able to fail a checkout.
     */
    public function queueSync(Order $order, bool $force = false): void
    {
        $document = $this->getDocuments()->markPending($order);

        if ($document->bexioId && !$force) {
            return;
        }

        Craft::$app->getQueue()->push(new SyncOrderJob([
            'orderId' => $order->id,
            'force' => $force,
        ]));
    }

    /**
     * Bexy's panel on Commerce's own order edit screen.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('bexy-viewDocuments')) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('bexy/_order-panel', [
                'order' => $order,
                'document' => $this->getDocuments()->getDocumentForOrder($order->id),
                'connected' => $this->getAuth()->isConnected(),
                'canPush' => Craft::$app->getUser()->checkPermission('bexy-pushDocuments'),
            ], View::TEMPLATE_MODE_CP);
        });
    }
}
