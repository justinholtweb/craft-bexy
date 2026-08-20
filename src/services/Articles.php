<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use yii\caching\TagDependency;
use craft\commerce\models\LineItem;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\helpers\Money;
use justinholtweb\bexy\models\DocumentPayload;
use justinholtweb\bexy\Plugin;

/**
 * Linking a Commerce line item to a bexio item, by SKU.
 *
 * Optional, and off by default. A document made of custom positions invoices correctly; linking
 * to real bexio items is what makes bexio's own per-item revenue and stock reporting work, and
 * that is only worth anything to a shop whose catalogue already lives in bexio too.
 */
class Articles extends Component
{
    /** bexio's `intern_code` is the item number, and it is what a Commerce SKU corresponds to. */
    public const SKU_FIELD = 'intern_code';

    public const CACHE_PREFIX = 'bexy.article.';

    /** Tagged so the lookups can be dropped without flushing the whole site's cache. */
    public const CACHE_TAG = 'bexy.articles';

    /** SKUs are looked up once an hour at most; a catalogue does not churn faster than that. */
    public const TTL = 3600;

    /** Cached to mean "looked, found nothing" — distinct from "have not looked". */
    private const MISS = 0;

    /**
     * The bexio item ID for a line item's SKU, or null when there is no match and Bexy is not
     * allowed to create one.
     */
    public function resolve(LineItem $lineItem, ?DocumentPayload $payload = null): ?int
    {
        $sku = trim($lineItem->getSku());

        if ($sku === '') {
            return null;
        }

        $cache = Craft::$app->getCache();
        $key = self::CACHE_PREFIX . md5($sku);
        $cached = $cache->get($key);

        if ($cached !== false) {
            return $cached === self::MISS ? null : (int)$cached;
        }

        try {
            $id = $this->findBySku($sku);

            if ($id === null && Plugin::getInstance()->getSettings()->createMissingArticles) {
                $id = $this->create($lineItem);
            }
        } catch (BexioApiException $e) {
            // An item lookup is an enhancement. It must never be the reason an invoice does not
            // get raised — the line falls back to a custom position and the document still goes.
            $payload?->addWarning(Craft::t('bexy', 'Could not look up “{sku}” in bexio: {message}', [
                'sku' => $sku,
                'message' => $e->getMessage(),
            ]));

            return null;
        }

        $cache->set($key, $id ?? self::MISS, self::TTL, new TagDependency(['tags' => self::CACHE_TAG]));

        return $id;
    }

    /**
     * @throws BexioApiException
     */
    public function findBySku(string $sku): ?int
    {
        $matches = Plugin::getInstance()->getApi()->search('/2.0/article', [
            ['field' => self::SKU_FIELD, 'value' => $sku, 'criteria' => '='],
        ], ['limit' => 2]);

        return $matches ? (int)$matches[0]['id'] : null;
    }

    /**
     * Create the bexio item from what Commerce knows about the purchasable.
     *
     * @throws BexioApiException
     */
    public function create(LineItem $lineItem): ?int
    {
        $settings = Plugin::getInstance()->getSettings();
        $userId = Plugin::getInstance()->getContacts()->bexioUserId();

        if (!$userId) {
            return null;
        }

        $payload = [
            // 1 is bexio's "physical item"; services are 2. Commerce cannot tell the difference
            // in general, and a physical item is the safer default because it can hold stock.
            'article_type_id' => 1,
            'user_id' => $userId,
            'intern_code' => mb_substr($lineItem->getSku(), 0, 255),
            'intern_name' => mb_substr($lineItem->getDescription(), 0, 255),
            'sale_price' => Money::price($lineItem->getSalePrice()),
            'is_stock' => false,
        ];

        if ($settings->defaultUnitId) {
            $payload['unit_id'] = $settings->defaultUnitId;
        }

        if ($settings->defaultAccountId) {
            $payload['account_id'] = $settings->defaultAccountId;
        }

        $taxId = $settings->taxIdFor($this->taxCategoryHandle($lineItem));

        if ($taxId) {
            $payload['tax_income_id'] = $taxId;
        }

        $created = Plugin::getInstance()->getApi()->post('/2.0/article', $payload, $lineItem->orderId);

        return isset($created['id']) ? (int)$created['id'] : null;
    }

    /**
     * Forget every cached SKU lookup.
     *
     * The keys are hashes of the SKU, so there is nothing to enumerate; the tag is what makes
     * dropping them possible without flushing the whole site's cache along with them.
     */
    public function flush(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    private function taxCategoryHandle(LineItem $lineItem): ?string
    {
        try {
            return $lineItem->getTaxCategory()->handle;
        } catch (\Throwable) {
            return null;
        }
    }
}
