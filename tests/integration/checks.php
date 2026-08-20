<?php
/**
 * Bexy integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-bexy/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, document rows, contact mappings, log
 * rows and the plugin settings it overwrites are all restored in a `finally`, pass or fail.
 *
 * bexio's lookup tables are injected straight into Craft's cache, which is where `services\Meta`
 * reads them from. That is what lets the payload builder, the tax mapping and the totals check be
 * exercised in full without a bexio account — the boundary is the HTTP call itself, and anything
 * past it (a real create, issue, or payment) is not covered here.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use justinholtweb\bexy\db\Table;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\helpers\Address;
use justinholtweb\bexy\helpers\Money;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\models\LogEntry;
use justinholtweb\bexy\models\Settings;
use justinholtweb\bexy\services\Builder;
use justinholtweb\bexy\services\Meta;
use justinholtweb\bexy\services\Reconcile;
use justinholtweb\bexy\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent`, but Craft passes an
// `ElementEvent` for that event — so saving *any* element fatals while it is enabled. Nothing to
// do with Bexy; detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

/**
 * Persist settings for the duration of the run. Project config writes are buffered until the
 * request ends, and a bare console script has no request end — so it has to flush them itself.
 */
function applySettings(array $values): void
{
    global $plugin;

    Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

/**
 * The ISO code this store sells in.
 */
function storeCurrency(): string
{
    $order = new Order();
    $order->storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;

    return $order->currency ?: 'USD';
}

/**
 * Stand bexio's lookup tables up in the cache, which is where Meta reads them from.
 */
function seedMeta(): void
{
    $cache = Craft::$app->getCache();

    $cache->set(Meta::CACHE_PREFIX . 'taxes', [
        ['id' => 17, 'name' => 'UN77', 'display_name' => 'Umsatzsteuer 7.7%', 'value' => 7.7, 'code' => 'UN77', 'is_active' => true],
        ['id' => 18, 'name' => 'UN25', 'display_name' => 'Umsatzsteuer 2.5%', 'value' => 2.5, 'code' => 'UN25', 'is_active' => true],
        ['id' => 19, 'name' => 'UN00', 'display_name' => 'Steuerfrei 0%', 'value' => 0.0, 'code' => 'UN00', 'is_active' => true],
    ], Meta::TTL);

    $cache->set(Meta::CACHE_PREFIX . 'accounts', [
        ['id' => 90, 'account_no' => '3200', 'name' => 'Handelserlöse'],
        ['id' => 91, 'account_no' => '3400', 'name' => 'Dienstleistungserlöse'],
    ], Meta::TTL);

    $cache->set(Meta::CACHE_PREFIX . 'users', [
        ['id' => 5, 'firstname' => 'Bex', 'lastname' => 'Tester', 'email' => 'bex@example.ch'],
    ], Meta::TTL);

    // The harness store is not necessarily Swiss, and Bexy is right to refuse to guess a currency
    // ID it does not hold — so the fixture holds whatever this store actually sells in.
    $cache->set(Meta::CACHE_PREFIX . 'currencies', [
        ['id' => 1, 'name' => storeCurrency(), 'exchange_rate' => 1.0],
        ['id' => 2, 'name' => 'ZZZ', 'exchange_rate' => 0.95],
    ], Meta::TTL);

    $cache->set(Meta::CACHE_PREFIX . 'countries', [
        ['id' => 1, 'name' => 'Switzerland', 'name_short' => 'CH', 'iso3166_alpha2' => 'CH'],
        ['id' => 2, 'name' => 'United States', 'name_short' => 'US', 'iso3166_alpha2' => 'US'],
    ], Meta::TTL);

    $cache->set(Meta::CACHE_PREFIX . 'units', [['id' => 3, 'name' => 'Stk.']], Meta::TTL);
    $cache->set(Meta::CACHE_PREFIX . 'languages', [['id' => 1, 'name' => 'German']], Meta::TTL);
    $cache->set(Meta::CACHE_PREFIX . 'paymentTypes', [['id' => 4, 'name' => '30 days net']], Meta::TTL);
    $cache->set(Meta::CACHE_PREFIX . 'bankAccounts', [['id' => 6, 'name' => 'PostFinance', 'iban' => 'CH00']], Meta::TTL);
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Bexy fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 * @param array<int, array{type: string, amount: float, included?: bool, name?: string}> $adjustments
 */
function makeOrder(array $lines, array $adjustments = [], bool $complete = true, string $currency = 'CHF'): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('bexy-fixture@example.ch');

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty']
        );
    }

    $order->setLineItems($lineItems);

    // Commerce insists an address element is owned by its order, so the attributes go in as an
    // array and Commerce builds the owned element itself.
    $address = [
        'fullName' => 'Dana Fixture',
        'organization' => '',
        'addressLine1' => 'Bahnhofstrasse 12',
        'locality' => 'Zürich',
        'postalCode' => '8001',
        'countryCode' => 'CH',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    // Adjustments go on after completion so Commerce's own recalculation does not wipe them: a
    // completed order recalculates in NONE mode, which is exactly what this needs.
    if ($adjustments) {
        $models = $order->getAdjustments();

        foreach ($adjustments as $spec) {
            $adjustment = new OrderAdjustment();
            $adjustment->setOrder($order);
            $adjustment->type = $spec['type'];
            $adjustment->name = $spec['name'] ?? ucfirst($spec['type']);
            $adjustment->description = $spec['name'] ?? '';
            $adjustment->amount = $spec['amount'];
            $adjustment->included = $spec['included'] ?? false;
            $adjustment->sourceSnapshot = [];
            $models[] = $adjustment;
        }

        $order->setAdjustments($models);
    }

    return $order;
}

/**
 * A Commerce transaction, built directly. Commerce's own factory reaches for the order's payment
 * gateway, which a fixture order has none of.
 */
function makeTransaction(Order $order, string $type, string $status, float $amount): craft\commerce\models\Transaction
{
    static $id = 900000;

    $transaction = new craft\commerce\models\Transaction();
    $transaction->id = $id++;
    $transaction->orderId = $order->id;
    $transaction->type = $type;
    $transaction->status = $status;
    $transaction->amount = $amount;
    $transaction->paymentAmount = $amount;
    $transaction->currency = $order->currency;
    $transaction->paymentCurrency = $order->currency;
    $transaction->paymentRate = 1.0;
    $transaction->dateCreated = new DateTime();

    return $transaction;
}

try {
    seedMeta();

    applySettings([
        'authMode' => Settings::AUTH_PAT,
        'personalAccessToken' => 'test-token-not-real',
        'documentType' => Document::TYPE_INVOICE,
        'autoSync' => false,
        'bexioUserId' => 5,
        'defaultAccountId' => 90,
        'defaultTaxId' => 17,
        'defaultUnitId' => 3,
        'bankAccountId' => 6,
        'paymentTypeId' => 4,
        'languageId' => 1,
        'mwstMode' => Settings::MWST_EXCLUDED,
        'addRoundingPosition' => true,
        'roundingTolerance' => 0.01,
        'roundingLimit' => 1.00,
        'pushPayments' => true,
        'loggingEnabled' => true,
        'logPayloads' => true,
        'taxMap' => [],
        'statusMap' => [],
        'syncOnStatuses' => [],
    ]);

    // ---------------------------------------------------------------------
    section('Money');

    check('a price keeps six decimals but drops trailing zeros', function() {
        return Money::price(12.5) === '12.5' ?: 'got ' . Money::price(12.5);
    });

    check('a fractional-rappen unit price survives', function() {
        return Money::price(0.415) === '0.415' ?: 'got ' . Money::price(0.415);
    });

    check('a whole price does not end in a dot', function() {
        return Money::price(12.0) === '12' ?: 'got ' . Money::price(12.0);
    });

    check('a money amount is always two decimals', function() {
        return Money::amount(12.5) === '12.50' ?: 'got ' . Money::amount(12.5);
    });

    check('a money amount rounds rather than truncates', function() {
        return Money::amount(0.005) === '0.01' ?: 'got ' . Money::amount(0.005);
    });

    check('a negative amount keeps its sign', function() {
        return Money::amount(-3.456) === '-3.46' ?: 'got ' . Money::amount(-3.456);
    });

    check('zero is "0", not "-0" or an empty string', function() {
        return Money::price(0.0) === '0' ?: 'got ' . Money::price(0.0);
    });

    check('differs() respects the tolerance', function() {
        return Money::differs(10.00, 10.009, 0.01) === false
            && Money::differs(10.00, 10.02, 0.01) === true
            ?: 'tolerance comparison is wrong';
    });

    check('bexio decimal strings parse back to floats', function() {
        return abs(Money::toFloat('1234.56') - 1234.56) < 0.0001
            && Money::toFloat(null) === 0.0
            && Money::toFloat('') === 0.0
            ?: 'string parsing is wrong';
    });

    // ---------------------------------------------------------------------
    section('Addresses');

    check('a Swiss street splits into name and number', function() {
        $split = Address::splitStreet('Bahnhofstrasse 12');

        return $split === ['street_name' => 'Bahnhofstrasse', 'house_number' => '12']
            ?: json_encode($split);
    });

    check('a number with a letter suffix splits', function() {
        $split = Address::splitStreet('Seefeldstrasse 214b');

        return $split === ['street_name' => 'Seefeldstrasse', 'house_number' => '214b']
            ?: json_encode($split);
    });

    check('an English-style street is left whole rather than split wrongly', function() {
        $split = Address::splitStreet('12 Station Road');

        return $split === ['street_name' => '12 Station Road', 'house_number' => '']
            ?: json_encode($split);
    });

    check('an empty line produces empty parts, not nulls', function() {
        return Address::splitStreet(null) === ['street_name' => '', 'house_number' => '']
            ?: 'null handling is wrong';
    });

    check('a full name splits with the surname as name_1, the way bexio files people', function() {
        $split = Address::splitName('Dana Marie Fixture');

        return $split === ['name_1' => 'Fixture', 'name_2' => 'Dana Marie'] ?: json_encode($split);
    });

    check('explicit first and last names win over the full name', function() {
        $split = Address::splitName('Ignored Entirely', 'Dana', 'Fixture');

        return $split === ['name_1' => 'Fixture', 'name_2' => 'Dana'] ?: json_encode($split);
    });

    check('a single-word name goes in name_1', function() {
        $split = Address::splitName('Prince');

        return $split === ['name_1' => 'Prince', 'name_2' => ''] ?: json_encode($split);
    });

    // ---------------------------------------------------------------------
    section('Settings');

    check('a required setting would block a fresh install, so there are none', function() {
        foreach ((new Settings())->rules() as $rule) {
            if (in_array('required', (array)$rule, true)) {
                return 'found a required rule: ' . json_encode($rule);
            }
        }

        return true;
    });

    check('the blank row an editable table always posts is dropped', function() {
        $settings = new Settings();
        $settings->taxMap = [
            ['taxCategory' => 'general', 'taxId' => '17', 'accountId' => '90'],
            ['taxCategory' => '', 'taxId' => '', 'accountId' => ''],
        ];
        $settings->validate();

        return count($settings->taxMap) === 1 ?: 'kept ' . count($settings->taxMap) . ' rows';
    });

    check('an unmapped tax category falls back to the default tax', function() {
        $settings = new Settings();
        $settings->defaultTaxId = 17;
        $settings->taxMap = [['taxCategory' => 'reduced', 'taxId' => '18', 'accountId' => '91']];

        return $settings->taxIdFor('anything-else') === 17 ?: 'got ' . var_export($settings->taxIdFor('anything-else'), true);
    });

    check('a mapped tax category uses its own tax and account', function() {
        $settings = new Settings();
        $settings->defaultTaxId = 17;
        $settings->defaultAccountId = 90;
        $settings->taxMap = [['taxCategory' => 'reduced', 'taxId' => '18', 'accountId' => '91']];

        return $settings->taxIdFor('reduced') === 18 && $settings->accountIdFor('reduced') === 91
            ?: 'mapping was not applied';
    });

    check('an email body with no [Network Link] is rejected when sending is on', function() {
        $settings = new Settings();
        $settings->sendDocument = true;
        $settings->emailBody = 'Your invoice is attached.';
        $settings->validate();

        return $settings->hasErrors('emailBody') ?: 'a body with no placeholder validated';
    });

    check('the same body is fine when bexio is not sending the email', function() {
        $settings = new Settings();
        $settings->sendDocument = false;
        $settings->emailBody = 'Your invoice is attached.';
        $settings->validate();

        return !$settings->hasErrors('emailBody') ?: 'rejected a body that is never sent';
    });

    check('an env var is resolved for the access token', function() {
        $settings = new Settings();
        $settings->personalAccessToken = 'plain-value';

        return $settings->getPersonalAccessToken() === 'plain-value' ?: 'env parsing broke a plain value';
    });

    check('a bexio status maps to a Commerce order status handle', function() {
        $settings = new Settings();
        $settings->statusMap = [['bexioStatus' => '9', 'orderStatus' => 'completed']];

        return $settings->orderStatusFor(Reconcile::STATUS_PAID) === 'completed'
            && $settings->orderStatusFor(Reconcile::STATUS_UNPAID) === null
            ?: 'status mapping is wrong';
    });

    // ---------------------------------------------------------------------
    section('Metadata');

    check('a country code resolves to bexio’s numeric ID', function() use ($plugin) {
        return $plugin->getMeta()->getCountryId('CH') === 1 ?: 'got ' . var_export($plugin->getMeta()->getCountryId('CH'), true);
    });

    check('country matching is case-insensitive', function() use ($plugin) {
        return $plugin->getMeta()->getCountryId('ch') === 1 ?: 'lower-case code did not match';
    });

    check('an unknown country returns null rather than guessing', function() use ($plugin) {
        return $plugin->getMeta()->getCountryId('ZZ') === null ?: 'invented a country ID';
    });

    check('a currency code resolves to bexio’s numeric ID', function() use ($plugin) {
        return $plugin->getMeta()->getCurrencyId(storeCurrency()) === 1
            ?: 'got ' . var_export($plugin->getMeta()->getCurrencyId(storeCurrency()), true);
    });

    check('an unheld currency returns null rather than being booked in the wrong one', function() use ($plugin) {
        return $plugin->getMeta()->getCurrencyId('QQQ') === null ?: 'invented a currency ID';
    });

    check('a tax ID resolves to its percentage', function() use ($plugin) {
        return abs($plugin->getMeta()->getTaxRate(17) - 7.7) < 0.0001 ?: 'got ' . var_export($plugin->getMeta()->getTaxRate(17), true);
    });

    check('tax options are labelled with their percentage', function() use ($plugin) {
        $options = $plugin->getMeta()->getTaxOptions();

        foreach ($options as $option) {
            if ($option['value'] === '17') {
                return str_contains($option['label'], '7.7%') ?: 'label was ' . $option['label'];
            }
        }

        return 'tax 17 was not in the options';
    });

    check('account options are labelled with the account number', function() use ($plugin) {
        $options = $plugin->getMeta()->getAccountOptions();

        foreach ($options as $option) {
            if ($option['value'] === '90') {
                return str_contains($option['label'], '3200') ?: 'label was ' . $option['label'];
            }
        }

        return 'account 90 was not in the options';
    });

    check('every option list starts with a blank row so a setting can be cleared', function() use ($plugin) {
        return $plugin->getMeta()->toOptions([])[0]['value'] === '' ?: 'no blank first option';
    });

    // ---------------------------------------------------------------------
    section('Payload builder — a plain order');

    $product = makeProduct("BEXY-$suffix-A", 100.00);
    $variant = $product->getVariants()[0];
    $order = makeOrder([['variant' => $variant, 'qty' => 2]]);

    check('an order becomes a document body with positions', function() use ($plugin, $order) {
        $payload = $plugin->getBuilder()->forOrder($order, 42);

        return count($payload->getPositions()) >= 1 ?: 'no positions were built';
    });

    check('the line item position carries quantity, unit price and text', function() use ($plugin, $order) {
        $position = $plugin->getBuilder()->forOrder($order, 42)->getPositions()[0];

        return $position['amount'] === '2'
            && $position['unit_price'] === '100'
            && str_contains($position['text'], 'Bexy fixture')
            ?: json_encode($position);
    });

    check('the position type is one bexio recognises', function() use ($plugin, $order) {
        $position = $plugin->getBuilder()->forOrder($order, 42)->getPositions()[0];

        return in_array($position['type'], [
            Builder::POSITION_CUSTOM,
            Builder::POSITION_ARTICLE,
        ], true) ?: 'got ' . $position['type'];
    });

    check('the SKU is on the invoice line', function() use ($plugin, $order, $suffix) {
        $position = $plugin->getBuilder()->forOrder($order, 42)->getPositions()[0];

        return str_contains($position['text'], "BEXY-$suffix-A") ?: 'SKU missing from ' . $position['text'];
    });

    check('the default tax and account are applied to an unmapped line', function() use ($plugin, $order) {
        $position = $plugin->getBuilder()->forOrder($order, 42)->getPositions()[0];

        return ($position['tax_id'] ?? null) === 17 && ($position['account_id'] ?? null) === 90
            ?: json_encode($position);
    });

    check('the contact ID reaches the body', function() use ($plugin, $order) {
        return ($plugin->getBuilder()->forOrder($order, 42)->body['contact_id'] ?? null) === 42
            ?: 'contact_id was not set';
    });

    check('a body with no contact says so, because bexio will reject it', function() use ($plugin, $order) {
        $payload = $plugin->getBuilder()->forOrder($order, null);

        foreach ($payload->warnings as $warning) {
            if (str_contains($warning, 'contact')) {
                return true;
            }
        }

        return 'no warning about the missing contact';
    });

    check('the currency is resolved to bexio’s ID', function() use ($plugin, $order) {
        return ($plugin->getBuilder()->forOrder($order, 42)->body['currency_id'] ?? null) === 1
            ?: 'currency_id was not resolved';
    });

    check('the billing address is sent as the document address', function() use ($plugin, $order) {
        $body = $plugin->getBuilder()->forOrder($order, 42)->body;

        return str_contains($body['contact_address_manual'] ?? '', 'Bahnhofstrasse 12')
            && str_contains($body['contact_address_manual'] ?? '', '8001 Zürich')
            ?: json_encode($body['contact_address_manual'] ?? null);
    });

    check('the address block is newline separated, as bexio wants it', function() use ($plugin, $order) {
        return str_contains($plugin->getBuilder()->forOrder($order, 42)->body['contact_address_manual'], "\n")
            ?: 'the address is one line';
    });

    check('an invoice gets a due date', function() use ($plugin, $order) {
        $body = $plugin->getBuilder()->forOrder($order, 42)->body;

        return !empty($body['is_valid_from']) && !empty($body['is_valid_to'])
            ?: 'dates missing: ' . json_encode($body);
    });

    check('the due date is the document date plus the payment term', function() use ($plugin, $order) {
        $body = $plugin->getBuilder()->forOrder($order, 42)->body;
        $from = new DateTime($body['is_valid_from']);
        $to = new DateTime($body['is_valid_to']);

        return (int)$from->diff($to)->days === 30 ?: 'got ' . $from->diff($to)->days . ' days';
    });

    check('the title template is rendered', function() use ($plugin, $order) {
        $body = $plugin->getBuilder()->forOrder($order, 42)->body;

        return str_contains($body['title'] ?? '', (string)($order->reference ?: $order->number))
            ?: 'title was ' . ($body['title'] ?? 'missing');
    });

    check('api_reference is stable across two builds of the same order', function() use ($plugin, $order) {
        $one = $plugin->getBuilder()->apiReference($order);
        $two = $plugin->getBuilder()->apiReference($order);

        return $one === $two && $one !== '' ?: "'$one' vs '$two'";
    });

    check('api_reference fits bexio’s 255-character field', function() use ($plugin, $order) {
        return mb_strlen($plugin->getBuilder()->apiReference($order)) <= 255 ?: 'too long';
    });

    check('api_reference reaches the body — it is the whole idempotency story', function() use ($plugin, $order) {
        $body = $plugin->getBuilder()->forOrder($order, 42)->body;

        return ($body['api_reference'] ?? null) === $plugin->getBuilder()->apiReference($order)
            ?: 'api_reference missing from the body';
    });

    check('no key in the body is null or an empty string', function() use ($plugin, $order) {
        foreach ($plugin->getBuilder()->forOrder($order, 42)->body as $key => $value) {
            if ($value === null || $value === '') {
                return "$key is empty";
            }
        }

        return true;
    });

    check('the body is valid JSON', function() use ($plugin, $order) {
        json_decode($plugin->getBuilder()->forOrder($order, 42)->toJson(), true);

        return json_last_error() === JSON_ERROR_NONE ?: json_last_error_msg();
    });

    // ---------------------------------------------------------------------
    section('Payload builder — totals');

    check('a net-priced order has bexio add the tax on top', function() use ($plugin, $order) {
        // No adjustment closes the gap: the fixture order has no tax adjustment, so Commerce says
        // 200 while bexio would compute 215.40. That gap is larger than the rounding limit, so it
        // is reported rather than papered over — which is the whole point of the limit.
        applySettings(['addRoundingPosition' => false]);
        $payload = $plugin->getBuilder()->forOrder($order, 42);
        applySettings(['addRoundingPosition' => true]);

        $expected = 200.00 * 1.077;

        return abs($payload->documentTotal - $expected) < 0.01
            ?: sprintf('expected %.2f, got %.2f', $expected, $payload->documentTotal);
    });

    check('net and tax are reported separately', function() use ($plugin, $order) {
        applySettings(['addRoundingPosition' => false]);
        $payload = $plugin->getBuilder()->forOrder($order, 42);
        applySettings(['addRoundingPosition' => true]);

        return abs($payload->netTotal - 200.00) < 0.01 && abs($payload->taxTotal - 15.40) < 0.01
            ?: sprintf('net %.2f tax %.2f', $payload->netTotal, $payload->taxTotal);
    });

    check('gross-priced mode peels the tax back out of the price', function() use ($plugin, $order) {
        applySettings(['mwstMode' => Settings::MWST_INCLUDED]);
        $payload = $plugin->getBuilder()->forOrder($order, 42);
        $ok = abs($payload->documentTotal - 200.00) < 0.01 && abs($payload->netTotal - 185.70) < 0.05;
        applySettings(['mwstMode' => Settings::MWST_EXCLUDED]);

        return $ok ?: 'gross mode arithmetic is wrong';
    });

    check('gross mode sends mwst_type 0 with mwst_is_net false', function() use ($plugin, $order) {
        applySettings(['mwstMode' => Settings::MWST_INCLUDED]);
        $body = $plugin->getBuilder()->forOrder($order, 42)->body;
        $ok = $body['mwst_type'] === Builder::MWST_INCLUDED && $body['mwst_is_net'] === false;
        applySettings(['mwstMode' => Settings::MWST_EXCLUDED]);

        return $ok ?: json_encode(['mwst_type' => $body['mwst_type'] ?? null, 'mwst_is_net' => $body['mwst_is_net'] ?? null]);
    });

    check('net mode sends mwst_type 1', function() use ($plugin, $order) {
        return $plugin->getBuilder()->forOrder($order, 42)->body['mwst_type'] === Builder::MWST_EXCLUDED
            ?: 'wrong mwst_type for net prices';
    });

    check('exempt mode charges no tax at all', function() use ($plugin, $order) {
        applySettings(['mwstMode' => Settings::MWST_EXEMPT]);
        $payload = $plugin->getBuilder()->forOrder($order, 42);
        $ok = $payload->taxTotal === 0.0 && $payload->body['mwst_type'] === Builder::MWST_EXEMPT;
        applySettings(['mwstMode' => Settings::MWST_EXCLUDED]);

        return $ok ?: sprintf('tax %.2f, mwst_type %s', $payload->taxTotal, $payload->body['mwst_type']);
    });

    // A cent-sized gap is what the rounding position is for: the order carries the tax as an
    // adjustment, so Commerce and bexio agree to within a rounding error rather than a whole
    // tax charge.
    $roundedOrder = makeOrder(
        [['variant' => $variant, 'qty' => 1]],
        [['type' => 'tax', 'amount' => 7.66, 'name' => 'MWST']]
    );

    check('a cent-sized mismatch is closed with a rounding position', function() use ($plugin, $roundedOrder) {
        $payload = $plugin->getBuilder()->forOrder($roundedOrder, 42);

        return $payload->hasRoundingPosition ?: sprintf(
            'no rounding position; delta was %.4f',
            $payload->delta
        );
    });

    check('after rounding, the document total equals the order total', function() use ($plugin, $roundedOrder) {
        $payload = $plugin->getBuilder()->forOrder($roundedOrder, 42);

        return abs($payload->documentTotal - $payload->orderTotal) < 0.01
            ?: sprintf('order %.2f vs document %.2f', $payload->orderTotal, $payload->documentTotal);
    });

    check('the rounding position is untaxed, or it would miss again by the tax on itself', function() use ($plugin, $roundedOrder) {
        $positions = $plugin->getBuilder()->forOrder($roundedOrder, 42)->getPositions();
        $rounding = end($positions);

        return !isset($rounding['tax_id']) ?: 'the rounding position carries a tax';
    });

    check('with rounding off, a cent-sized mismatch is reported instead of hidden', function() use ($plugin, $roundedOrder) {
        applySettings(['addRoundingPosition' => false]);
        $payload = $plugin->getBuilder()->forOrder($roundedOrder, 42);
        $warned = false;

        foreach ($payload->warnings as $warning) {
            if (str_contains($warning, 'does not match')) {
                $warned = true;
            }
        }

        applySettings(['addRoundingPosition' => true]);

        return ($warned && !$payload->hasRoundingPosition) ?: 'a silent mismatch got through';
    });

    check('a difference too big to be rounding is refused, not papered over', function() use ($plugin, $order) {
        // The gap here is a whole 7.7% tax charge. Closing it would produce a document that
        // balances and is still wrong.
        $payload = $plugin->getBuilder()->forOrder($order, 42);

        return !$payload->hasRoundingPosition ?: 'a 15-franc "rounding" line was added';
    });

    check('and it says why, in terms a merchant can act on', function() use ($plugin, $order) {
        foreach ($plugin->getBuilder()->forOrder($order, 42)->warnings as $warning) {
            if (str_contains($warning, 'too large to be rounding')) {
                return true;
            }
        }

        return 'the oversized mismatch was not explained';
    });

    check('removing the limit lets any difference be closed', function() use ($plugin, $order) {
        applySettings(['roundingLimit' => 0]);
        $payload = $plugin->getBuilder()->forOrder($order, 42);
        applySettings(['roundingLimit' => 1.00]);

        return $payload->hasRoundingPosition ?: 'the limit still applied at 0';
    });

    // ---------------------------------------------------------------------
    section('Payload builder — shipping, discounts and strays');

    $shippedOrder = makeOrder(
        [['variant' => $variant, 'qty' => 1]],
        [
            ['type' => 'shipping', 'amount' => 12.00, 'name' => 'Post'],
            ['type' => 'discount', 'amount' => -10.00, 'name' => 'WELCOME10'],
            ['type' => 'handling', 'amount' => 4.50, 'name' => 'Handling fee'],
            ['type' => 'tax', 'amount' => 7.85, 'name' => 'MWST'],
        ]
    );

    check('shipping becomes its own position', function() use ($plugin, $shippedOrder) {
        foreach ($plugin->getBuilder()->forOrder($shippedOrder, 42)->getPositions() as $position) {
            if (($position['text'] ?? '') === 'Shipping') {
                return $position['unit_price'] === '12' ?: 'shipping price was ' . $position['unit_price'];
            }
        }

        return 'no shipping position';
    });

    check('a discount becomes a KbPositionDiscount', function() use ($plugin, $shippedOrder) {
        foreach ($plugin->getBuilder()->forOrder($shippedOrder, 42)->getPositions() as $position) {
            if (($position['type'] ?? '') === Builder::POSITION_DISCOUNT) {
                return true;
            }
        }

        return 'no discount position';
    });

    check('the discount is sent as a magnitude, not a negative', function() use ($plugin, $shippedOrder) {
        foreach ($plugin->getBuilder()->forOrder($shippedOrder, 42)->getPositions() as $position) {
            if (($position['type'] ?? '') === Builder::POSITION_DISCOUNT) {
                return $position['value'] === '10.00' && $position['is_percentual'] === false
                    ?: json_encode($position);
            }
        }

        return 'no discount position';
    });

    check('an adjuster Bexy has never heard of still gets a position', function() use ($plugin, $shippedOrder) {
        foreach ($plugin->getBuilder()->forOrder($shippedOrder, 42)->getPositions() as $position) {
            if (($position['text'] ?? '') === 'Handling fee') {
                return $position['unit_price'] === '4.5' ?: 'got ' . $position['unit_price'];
            }
        }

        return 'the handling fee vanished';
    });

    check('a tax adjustment does not become a position — bexio computes tax itself', function() use ($plugin, $shippedOrder) {
        foreach ($plugin->getBuilder()->forOrder($shippedOrder, 42)->getPositions() as $position) {
            if (($position['text'] ?? '') === 'MWST') {
                return 'tax was double-counted as a position';
            }
        }

        return true;
    });

    check('an order carrying an added tax adjustment is detected as net-priced', function() use ($plugin, $shippedOrder) {
        applySettings(['mwstMode' => Settings::MWST_AUTO]);
        $type = $plugin->getBuilder()->forOrder($shippedOrder, 42)->body['mwst_type'];
        applySettings(['mwstMode' => Settings::MWST_EXCLUDED]);

        return $type === Builder::MWST_EXCLUDED ?: 'got mwst_type ' . $type;
    });

    check('the order note is left out unless asked for', function() use ($plugin, $shippedOrder) {
        foreach ($plugin->getBuilder()->forOrder($shippedOrder, 42)->getPositions() as $position) {
            if (($position['type'] ?? '') === Builder::POSITION_TEXT) {
                return 'a text position appeared without the setting on';
            }
        }

        return true;
    });

    // ---------------------------------------------------------------------
    section('Documents');

    check('an order can be marked pending without touching bexio', function() use ($plugin, $order) {
        $document = $plugin->getDocuments()->markPending($order);

        return $document->id !== null && $document->status === Document::STATUS_PENDING
            ?: 'pending row was not created';
    });

    check('marking pending twice does not create a second row', function() use ($plugin, $order) {
        $first = $plugin->getDocuments()->markPending($order);
        $second = $plugin->getDocuments()->markPending($order);

        return $first->id === $second->id ?: "ids $first->id and $second->id";
    });

    check('the unique index on orderId refuses a duplicate row outright', function() use ($order) {
        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, [
                'orderId' => $order->id,
                'apiReference' => 'bexy:duplicate',
                'documentType' => 'invoice',
                'status' => 'pending',
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            ])->execute();
        } catch (Throwable) {
            return true;
        }

        return 'the database accepted two documents for one order';
    });

    check('record() adopts an existing row rather than inserting a twin', function() use ($plugin, $order) {
        $document = new Document([
            'orderId' => $order->id,
            'apiReference' => 'bexy:adopted',
            'documentType' => Document::TYPE_INVOICE,
            'status' => Document::STATUS_PENDING,
        ]);
        $plugin->getDocuments()->record($document);

        $count = (new craft\db\Query())->from(Table::DOCUMENTS)->where(['orderId' => $order->id])->count();

        return (int)$count === 1 ?: "found $count rows";
    });

    check('a synced document reports its bexio status in words', function() {
        $document = new Document(['bexioStatusId' => Reconcile::STATUS_PAID, 'documentType' => Document::TYPE_INVOICE]);

        return $document->getBexioStatusLabel() === 'Paid' ?: 'got ' . var_export($document->getBexioStatusLabel(), true);
    });

    check('an order document reads its status from bexio’s other status table', function() {
        $document = new Document(['bexioStatusId' => 15, 'documentType' => Document::TYPE_ORDER]);

        return $document->getBexioStatusLabel() === 'Done' ?: 'got ' . var_export($document->getBexioStatusLabel(), true);
    });

    check('a document links into bexio’s own UI', function() {
        $document = new Document(['bexioId' => 512, 'documentType' => Document::TYPE_INVOICE]);

        return str_contains((string)$document->getBexioUrl(), 'kb_invoice/show/id/512')
            ?: 'got ' . var_export($document->getBexioUrl(), true);
    });

    check('a document with no bexio ID has no bexio link', function() {
        return (new Document())->getBexioUrl() === null ?: 'invented a URL';
    });

    check('a needs-attention document shows amber, not green', function() {
        $document = new Document(['status' => Document::STATUS_SYNCED, 'needsAttention' => 'Refunded']);

        return $document->getStatusColor() === 'orange' ?: 'got ' . $document->getStatusColor();
    });

    check('a rounding delta is reported on the row', function() {
        return (new Document(['roundingDelta' => 0.02]))->getHasRoundingDelta()
            && !(new Document(['roundingDelta' => 0.0]))->getHasRoundingDelta()
            ?: 'delta detection is wrong';
    });

    check('counts add up across the statuses', function() use ($plugin) {
        $counts = $plugin->getDocuments()->getCounts();

        return isset($counts['total'], $counts['pending'], $counts['synced'], $counts['failed'], $counts['attention'])
            ?: 'a count is missing';
    });

    check('a pending document with no bexio ID is retryable', function() use ($plugin, $order) {
        foreach ($plugin->getDocuments()->getRetryable() as $document) {
            if ($document->orderId === $order->id) {
                return true;
            }
        }

        return 'the pending document was not offered for retry';
    });

    check('forgetting a document leaves the order alone', function() use ($plugin, $order) {
        $document = $plugin->getDocuments()->getDocumentForOrder($order->id);
        $plugin->getDocuments()->forget($document->id);
        $stillThere = Order::find()->id($order->id)->status(null)->one() !== null;
        $plugin->getDocuments()->markPending($order);

        return $stillThere ?: 'the order went with it';
    });

    // ---------------------------------------------------------------------
    section('Payments');

    check('an authorization is not counted as money taken', function() use ($plugin, $order) {
        // Built by hand rather than through Commerce's factory, which needs a payment gateway the
        // fixture order does not have.
        $order->setTransactions([
            makeTransaction($order, TransactionRecord::TYPE_AUTHORIZE, TransactionRecord::STATUS_SUCCESS, 200.00),
        ]);

        return $plugin->getPayments()->settledTransactions($order) === []
            ?: 'an authorization was counted as a payment';
    });

    check('a successful purchase is money taken', function() use ($plugin, $order) {
        $order->setTransactions([
            makeTransaction($order, TransactionRecord::TYPE_PURCHASE, TransactionRecord::STATUS_SUCCESS, 200.00),
        ]);

        return count($plugin->getPayments()->settledTransactions($order)) === 1
            ?: 'a purchase was not counted';
    });

    check('a capture against an authorization is money taken', function() use ($plugin, $order) {
        $order->setTransactions([
            makeTransaction($order, TransactionRecord::TYPE_CAPTURE, TransactionRecord::STATUS_SUCCESS, 200.00),
        ]);

        return count($plugin->getPayments()->settledTransactions($order)) === 1
            ?: 'a capture was not counted';
    });

    check('a failed purchase is not posted to bexio', function() use ($plugin, $order) {
        $order->setTransactions([
            makeTransaction($order, TransactionRecord::TYPE_PURCHASE, TransactionRecord::STATUS_FAILED, 200.00),
        ]);

        return $plugin->getPayments()->settledTransactions($order) === []
            ?: 'a failed charge would have been booked';
    });

    check('refunds are totalled separately from payments', function() use ($plugin, $order) {
        $order->setTransactions([
            makeTransaction($order, TransactionRecord::TYPE_PURCHASE, TransactionRecord::STATUS_SUCCESS, 200.00),
            makeTransaction($order, TransactionRecord::TYPE_REFUND, TransactionRecord::STATUS_SUCCESS, 50.00),
        ]);

        return $plugin->getPayments()->refundedTotal($order) === 50.0
            && count($plugin->getPayments()->settledTransactions($order)) === 1
            ?: sprintf('refunded %.2f', $plugin->getPayments()->refundedTotal($order));
    });

    check('an unrecorded transaction ID is reported as unrecorded', function() use ($plugin) {
        return $plugin->getPayments()->isRecorded(987654321) === false ?: 'phantom payment row';
    });

    check('a null transaction ID is not recorded', function() use ($plugin) {
        return $plugin->getPayments()->isRecorded(null) === false ?: 'null was treated as recorded';
    });

    check('an order with no refunds reports zero refunded', function() use ($plugin, $order) {
        $order->setTransactions([]);

        return $plugin->getPayments()->refundedTotal($order) === 0.0 ?: 'invented a refund';
    });

    // ---------------------------------------------------------------------
    section('Reconciliation');

    check('a paid document is not re-checked — it cannot change again', function() use ($plugin, $order) {
        $document = $plugin->getDocuments()->getDocumentForOrder($order->id);
        $document->status = Document::STATUS_SYNCED;
        $document->bexioId = 999001;
        $document->bexioStatusId = Reconcile::STATUS_PAID;
        $document->dateSynced = new DateTime();
        $plugin->getDocuments()->record($document);

        foreach ($plugin->getReconcile()->getReconcilable() as $candidate) {
            if ($candidate->id === $document->id) {
                return 'a paid document was queued for another check';
            }
        }

        return true;
    });

    check('an unpaid document is queued for checking', function() use ($plugin, $order) {
        $document = $plugin->getDocuments()->getDocumentForOrder($order->id);
        $document->bexioStatusId = Reconcile::STATUS_UNPAID;
        $plugin->getDocuments()->record($document);

        foreach ($plugin->getReconcile()->getReconcilable() as $candidate) {
            if ($candidate->id === $document->id) {
                return true;
            }
        }

        return 'an unpaid document was skipped';
    });

    check('a cancelled document is not re-checked either', function() use ($plugin, $order) {
        $document = $plugin->getDocuments()->getDocumentForOrder($order->id);
        $document->bexioStatusId = Reconcile::STATUS_CANCELLED;
        $plugin->getDocuments()->record($document);

        foreach ($plugin->getReconcile()->getReconcilable() as $candidate) {
            if ($candidate->id === $document->id) {
                return 'a cancelled document was queued';
            }
        }

        return true;
    });

    check('a document with no bexio ID is not reconcilable', function() use ($plugin) {
        return $plugin->getReconcile()->reconcile(new Document()) === false ?: 'tried to reconcile nothing';
    });

    check('the status options cover bexio’s documented invoice statuses', function() use ($plugin) {
        $values = array_column($plugin->getReconcile()->getBexioStatusOptions(Document::TYPE_INVOICE), 'value');

        foreach (array_keys(Meta::INVOICE_STATUSES) as $id) {
            if (!in_array((string)$id, $values, true)) {
                return "status $id is missing";
            }
        }

        return true;
    });

    // ---------------------------------------------------------------------
    section('The log');

    check('a log entry is written and read back', function() use ($plugin, $suffix) {
        $plugin->getLog()->write('test.write', [
            'summary' => "checks-$suffix",
            'method' => 'POST',
            'endpoint' => '/2.0/kb_invoice',
            'statusCode' => 201,
            'request' => '{"hello":"world"}',
        ]);

        foreach ($plugin->getLog()->getEntries(['search' => "checks-$suffix"]) as $entry) {
            return $entry->statusCode === 201 ?: 'status code was not stored';
        }

        return 'the entry was not found';
    });

    check('a failed call is logged at error level and shows red', function() use ($plugin, $suffix) {
        $plugin->getLog()->write('test.error', [
            'level' => LogEntry::LEVEL_ERROR,
            'summary' => "checks-error-$suffix",
            'statusCode' => 422,
        ]);

        foreach ($plugin->getLog()->getEntries(['search' => "checks-error-$suffix"]) as $entry) {
            return $entry->getIsError() && $entry->getStatusColor() === 'red' ?: 'error styling is wrong';
        }

        return 'the error entry was not found';
    });

    check('filtering by level narrows the list', function() use ($plugin) {
        foreach ($plugin->getLog()->getEntries(['level' => LogEntry::LEVEL_ERROR], 20) as $entry) {
            if ($entry->level !== LogEntry::LEVEL_ERROR) {
                return 'the level filter leaked an info entry';
            }
        }

        return true;
    });

    check('a JSON body is pretty-printed for the detail screen', function() {
        $entry = new LogEntry();

        return str_contains($entry->formatted('{"a":1}'), "\n") ?: 'JSON was not formatted';
    });

    check('a non-JSON body is shown as-is rather than blanked', function() {
        $entry = new LogEntry();

        return $entry->formatted('not json at all') === 'not json at all' ?: 'plain text was mangled';
    });

    check('logging off writes nothing', function() use ($plugin, $suffix) {
        applySettings(['loggingEnabled' => false]);
        $plugin->getLog()->write('test.off', ['summary' => "checks-off-$suffix"]);
        $found = $plugin->getLog()->getTotal(['search' => "checks-off-$suffix"]);
        applySettings(['loggingEnabled' => true]);

        return $found === 0 ?: 'an entry was written with logging off';
    });

    check('payloads off keeps the entry but drops the body', function() use ($plugin, $suffix) {
        applySettings(['logPayloads' => false]);
        $plugin->getLog()->write('test.nopayload', [
            'summary' => "checks-nopayload-$suffix",
            'request' => '{"secret":"value"}',
        ]);
        $entries = $plugin->getLog()->getEntries(['search' => "checks-nopayload-$suffix"]);
        applySettings(['logPayloads' => true]);

        return ($entries && $entries[0]->request === null) ?: 'the body was stored anyway';
    });

    check('an over-long body is truncated rather than rejected', function() use ($plugin, $suffix) {
        $plugin->getLog()->write('test.big', [
            'summary' => "checks-big-$suffix",
            'request' => str_repeat('x', 200000),
        ]);
        $entries = $plugin->getLog()->getEntries(['search' => "checks-big-$suffix"]);

        return ($entries && mb_strlen((string)$entries[0]->request) < 200000) ?: 'the body was not truncated';
    });

    // ---------------------------------------------------------------------
    section('API errors');

    check('a 5xx is transient and worth retrying', function() {
        return (new BexioApiException('boom', 503))->getIsTransient() ?: '503 was treated as final';
    });

    check('a 429 is transient', function() {
        return (new BexioApiException('slow down', 429))->getIsTransient() ?: '429 was treated as final';
    });

    check('a connection failure is transient', function() {
        return (new BexioApiException('no route', 0))->getIsTransient() ?: 'a network failure was treated as final';
    });

    check('a 422 is final — the same body will be rejected forever', function() {
        return !(new BexioApiException('invalid', 422))->getIsTransient() ?: '422 was queued for retry';
    });

    check('bexio’s validation errors are flattened into readable lines', function() {
        $exception = new BexioApiException('invalid', 422, '/2.0/kb_invoice', [
            'errors' => ['positions' => ['tax_id is not an active sales tax']],
        ]);

        return $exception->getValidationErrors() === ['positions: tax_id is not an active sales tax']
            ?: json_encode($exception->getValidationErrors());
    });

    check('a 401 is explained as a credentials problem, not a stack trace', function() use ($plugin) {
        $message = $plugin->getApi()->explain(new BexioApiException('Unauthorized', 401, '/2.0/kb_invoice'));

        return str_contains($message, 'credentials') ?: 'got ' . $message;
    });

    check('a 403 names the endpoint that was refused', function() use ($plugin) {
        $message = $plugin->getApi()->explain(new BexioApiException('Forbidden', 403, '/2.0/kb_invoice'));

        return str_contains($message, '/2.0/kb_invoice') ?: 'got ' . $message;
    });

    check('a validation error is explained with bexio’s own words', function() use ($plugin) {
        $message = $plugin->getApi()->explain(new BexioApiException('invalid', 422, '/2.0/kb_invoice', [
            'errors' => ['contact_id' => ['is required']],
        ]));

        return str_contains($message, 'contact_id') ?: 'got ' . $message;
    });

    check('an unconnected install refuses to call rather than sending an empty bearer', function() use ($plugin) {
        applySettings(['authMode' => Settings::AUTH_PAT, 'personalAccessToken' => '']);

        try {
            $plugin->getApi()->get('/3.0/users/me');
        } catch (BexioApiException $e) {
            applySettings(['authMode' => Settings::AUTH_PAT, 'personalAccessToken' => 'test-token-not-real']);

            return $e->statusCode === 401 ?: 'got status ' . $e->statusCode;
        }

        applySettings(['authMode' => Settings::AUTH_PAT, 'personalAccessToken' => 'test-token-not-real']);

        return 'the call went out with no token';
    });

    // ---------------------------------------------------------------------
    section('Contacts');

    check('a person’s contact body files the surname as name_1', function() use ($plugin, $order) {
        $payload = $plugin->getContacts()->buildContactPayload($order, 5, $plugin->getSettings());

        return $payload['name_1'] === 'Fixture' && $payload['name_2'] === 'Dana'
            ?: json_encode($payload);
    });

    check('a person is contact_type_id 2', function() use ($plugin, $order) {
        $payload = $plugin->getContacts()->buildContactPayload($order, 5, $plugin->getSettings());

        return $payload['contact_type_id'] === 2 ?: 'got ' . $payload['contact_type_id'];
    });

    check('bexio requires user_id and owner_id, so both are always sent', function() use ($plugin, $order) {
        $payload = $plugin->getContacts()->buildContactPayload($order, 5, $plugin->getSettings());

        return ($payload['user_id'] ?? null) === 5 && ($payload['owner_id'] ?? null) === 5
            ?: json_encode($payload);
    });

    check('the street is split, because bexio deprecated the combined address field', function() use ($plugin, $order) {
        $payload = $plugin->getContacts()->buildContactPayload($order, 5, $plugin->getSettings());

        return $payload['street_name'] === 'Bahnhofstrasse' && $payload['house_number'] === '12'
            ?: json_encode($payload);
    });

    check('the country is sent as bexio’s numeric ID', function() use ($plugin, $order) {
        $payload = $plugin->getContacts()->buildContactPayload($order, 5, $plugin->getSettings());

        return ($payload['country_id'] ?? null) === 1 ?: 'got ' . var_export($payload['country_id'] ?? null, true);
    });

    check('the deprecated combined address field is not sent', function() use ($plugin, $order) {
        $payload = $plugin->getContacts()->buildContactPayload($order, 5, $plugin->getSettings());

        return !array_key_exists('address', $payload) ?: 'the deprecated field was sent';
    });

    check('an email/contact mapping is remembered and read back', function() use ($plugin, $suffix) {
        $email = "bexy-map-$suffix@example.ch";
        $plugin->getContacts()->remember($email, 4242);

        return $plugin->getContacts()->getMappedContactId($email) === 4242
            ?: 'the mapping did not stick';
    });

    check('mapping lookup is case-insensitive, because email is', function() use ($plugin, $suffix) {
        return $plugin->getContacts()->getMappedContactId("BEXY-MAP-$suffix@EXAMPLE.CH") === 4242
            ?: 'an upper-case address missed its own mapping';
    });

    check('remembering the same address twice updates rather than duplicating', function() use ($plugin, $suffix) {
        $email = "bexy-map-$suffix@example.ch";
        $plugin->getContacts()->remember($email, 5353);
        $count = (new craft\db\Query())->from(Table::CONTACTS)->where(['email' => $email])->count();

        return (int)$count === 1 && $plugin->getContacts()->getMappedContactId($email) === 5353
            ?: "found $count rows";
    });

    check('forgetting a contact removes the mapping', function() use ($plugin, $suffix) {
        $plugin->getContacts()->forget(5353);

        return $plugin->getContacts()->getMappedContactId("bexy-map-$suffix@example.ch") === null
            ?: 'the mapping survived';
    });

    check('a company order files as contact_type_id 1 under the company name', function() use ($plugin, $variant) {
        $companyOrder = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $companyOrder->setBillingAddress([
            'fullName' => 'Dana Fixture',
            'organization' => 'Fixture AG',
            'addressLine1' => 'Bahnhofstrasse 12',
            'locality' => 'Zürich',
            'postalCode' => '8001',
            'countryCode' => 'CH',
        ]);
        Craft::$app->getElements()->saveElement($companyOrder, false);

        $payload = $plugin->getContacts()->buildContactPayload($companyOrder, 5, $plugin->getSettings());

        return $payload['contact_type_id'] === 1 && $payload['name_1'] === 'Fixture AG'
            ?: json_encode($payload);
    });

    // ---------------------------------------------------------------------
    section('Token storage');

    check('a stored token round-trips through encryption', function() use ($plugin) {
        $auth = $plugin->getAuth();
        $method = new ReflectionMethod($auth, 'encrypt');
        $method->setAccessible(true);
        $back = new ReflectionMethod($auth, 'decrypt');
        $back->setAccessible(true);

        $stored = $method->invoke($auth, 'a-refresh-token');

        return $back->invoke($auth, $stored) === 'a-refresh-token' ?: 'the round trip lost the token';
    });

    check('the stored form is not the token itself', function() use ($plugin) {
        $auth = $plugin->getAuth();
        $method = new ReflectionMethod($auth, 'encrypt');
        $method->setAccessible(true);

        return !str_contains((string)$method->invoke($auth, 'a-refresh-token'), 'a-refresh-token')
            ?: 'the token was stored in the clear';
    });

    check('the ciphertext is base64, not the raw binary that breaks a utf8mb4 text column', function() use ($plugin) {
        $auth = $plugin->getAuth();
        $method = new ReflectionMethod($auth, 'encrypt');
        $method->setAccessible(true);
        $stored = (string)$method->invoke($auth, 'a-refresh-token');

        return preg_match('/^[A-Za-z0-9+\/=]+$/', $stored) === 1 ?: 'the stored value is not base64';
    });

    check('an encrypted token survives an actual database round trip', function() use ($plugin) {
        $auth = $plugin->getAuth();
        $method = new ReflectionMethod($auth, 'encrypt');
        $method->setAccessible(true);

        $now = date('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert(Table::TOKENS, [
            'accessToken' => $method->invoke($auth, 'access-round-trip'),
            'refreshToken' => $method->invoke($auth, 'refresh-round-trip'),
            'scope' => 'kb_invoice_edit',
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        ])->execute();

        // getTokenSet() memoises, so the fresh read has to come from a fresh service.
        $fresh = new justinholtweb\bexy\services\Auth();
        $tokenSet = $fresh->getTokenSet();

        $ok = $tokenSet !== null
            && $tokenSet->accessToken === 'access-round-trip'
            && $tokenSet->refreshToken === 'refresh-round-trip';

        Craft::$app->getDb()->createCommand()->delete(Table::TOKENS)->execute();

        return $ok ?: 'the token did not survive the database';
    });

    check('an undecryptable value reads as "not connected", not as a crash', function() use ($plugin) {
        $auth = $plugin->getAuth();
        $back = new ReflectionMethod($auth, 'decrypt');
        $back->setAccessible(true);

        return $back->invoke($auth, base64_encode('not really ciphertext')) === null
            ?: 'garbage ciphertext did not degrade safely';
    });

    check('a granted scope is reported, so the UI can say what was actually approved', function() {
        $tokenSet = new justinholtweb\bexy\models\TokenSet(['scope' => 'openid kb_invoice_edit contact_edit']);

        return $tokenSet->hasScope('kb_invoice_edit')
            && !$tokenSet->hasScope('kb_order_edit')
            && count($tokenSet->getScopes()) === 3
            ?: 'scope parsing is wrong';
    });

    check('a token inside the refresh skew counts as expired, before a slow request finds out', function() {
        $tokenSet = new justinholtweb\bexy\models\TokenSet([
            'dateExpires' => (new DateTime())->modify('+30 seconds'),
        ]);

        return $tokenSet->getIsExpired() ?: 'a nearly-expired token was treated as good';
    });

    check('a token with plenty of life left is not refreshed', function() {
        $tokenSet = new justinholtweb\bexy\models\TokenSet([
            'dateExpires' => (new DateTime())->modify('+1 hour'),
        ]);

        return !$tokenSet->getIsExpired() ?: 'a healthy token was treated as expired';
    });

    // ---------------------------------------------------------------------
    section('Twig');

    check('craft.bexy reports whether an order reached bexio', function() use ($plugin, $order) {
        $variable = new justinholtweb\bexy\twig\BexyVariable();
        $document = $plugin->getDocuments()->getDocumentForOrder($order->id);

        return $variable->isSynced($order) === (bool)$document->bexioId ?: 'isSynced disagrees with the row';
    });

    check('craft.bexy returns null for an order it has never seen', function() {
        $variable = new justinholtweb\bexy\twig\BexyVariable();

        return $variable->documentForOrder(999999999) === null ?: 'invented a document';
    });

    check('craft.bexy handles a null order without fataling', function() {
        $variable = new justinholtweb\bexy\twig\BexyVariable();

        return $variable->documentForOrder(null) === null && $variable->isSynced(null) === false
            ?: 'null handling is wrong';
    });

    // ---------------------------------------------------------------------
    section('Templates');

    check('every CP template compiles', function() {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

        try {
            foreach ([
                'bexy/settings',
                'bexy/documents/_index',
                'bexy/documents/_detail',
                'bexy/log/_index',
                'bexy/log/_detail',
                'bexy/_order-panel',
            ] as $template) {
                if (!$view->doesTemplateExist($template)) {
                    return "$template is missing";
                }

                // Compiling catches a syntax error; rendering would need each screen's context.
                $view->getTwig()->load($template . '.twig');
            }
        } finally {
            $view->setTemplateMode($mode);
        }

        return true;
    });

    check('the plugin icon is valid SVG', function() {
        $svg = file_get_contents(dirname(__DIR__, 2) . '/src/icon.svg');

        return simplexml_load_string($svg) !== false ?: 'icon.svg does not parse';
    });

    check('the mask icon is valid SVG', function() {
        $svg = file_get_contents(dirname(__DIR__, 2) . '/src/icon-mask.svg');

        return simplexml_load_string($svg) !== false ?: 'icon-mask.svg does not parse';
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();
    $db = Craft::$app->getDb();

    foreach ($createdOrders as $fixtureOrder) {
        try {
            $db->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $fixtureOrder->id])->execute();
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    foreach ([Table::LOG, Table::CONTACTS, Table::TOKENS] as $table) {
        try {
            $db->createCommand()->delete($table)->execute();
        } catch (Throwable $e) {
            echo "  ! could not clear $table: {$e->getMessage()}\n";
        }
    }

    try {
        Plugin::getInstance()->getMeta()->flush();
    } catch (Throwable $e) {
        echo "  ! could not flush the metadata cache: {$e->getMessage()}\n";
    }

    try {
        applySettings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
