<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\Plugin;

/**
 * bexio's lookup tables — users, accounts, taxes, currencies, units, languages, payment types,
 * bank accounts and countries.
 *
 * Every one of them is an ID the merchant has to pick in settings, and none of them can be typed
 * from memory. They are fetched once and cached, because a settings screen that makes nine API
 * calls on every page load spends the shop's rate limit on chrome.
 */
class Meta extends Component
{
    /** How long a lookup table stays cached. These change about once a year. */
    public const TTL = 3600;

    public const CACHE_PREFIX = 'bexy.meta.';

    /**
     * bexio's document status IDs for invoices, which the API documents but does not expose.
     */
    public const INVOICE_STATUSES = [
        7 => 'Draft',
        8 => 'Pending',
        9 => 'Paid',
        16 => 'Partial',
        19 => 'Cancelled',
        31 => 'Unpaid',
    ];

    /** And for orders. */
    public const ORDER_STATUSES = [
        5 => 'Draft',
        6 => 'Pending',
        15 => 'Done',
        21 => 'Cancelled',
    ];

    /**
     * Sales taxes that are valid on a document today.
     *
     * Only active sales taxes may be referenced by a quote, order or invoice — picking any other
     * kind produces a 422 at push time, long after the merchant chose it in settings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTaxes(): array
    {
        return $this->fetch('taxes', '/3.0/taxes', [
            'types' => 'sales_tax',
            'scope' => 'active',
            'limit' => 2000,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAccounts(): array
    {
        return $this->fetch('accounts', '/2.0/accounts', ['limit' => 2000]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUsers(): array
    {
        return $this->fetch('users', '/3.0/users', ['limit' => 2000]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCurrencies(): array
    {
        return $this->fetch('currencies', '/3.0/currencies', ['limit' => 2000]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUnits(): array
    {
        return $this->fetch('units', '/2.0/unit', ['limit' => 2000]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLanguages(): array
    {
        return $this->fetch('languages', '/2.0/language', ['limit' => 2000]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentTypes(): array
    {
        return $this->fetch('paymentTypes', '/2.0/payment_type', ['limit' => 2000]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBankAccounts(): array
    {
        return $this->fetch('bankAccounts', '/3.0/banking/accounts', ['limit' => 2000]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCountries(): array
    {
        return $this->fetch('countries', '/2.0/country', ['limit' => 2000]);
    }

    /**
     * bexio's ID for an ISO 3166-1 alpha-2 country code.
     */
    public function getCountryId(?string $alpha2): ?int
    {
        if (!$alpha2) {
            return null;
        }

        foreach ($this->getCountries() as $country) {
            if (strcasecmp((string)($country['iso3166_alpha2'] ?? ''), $alpha2) === 0) {
                return (int)$country['id'];
            }
        }

        return null;
    }

    /**
     * bexio's ID for an ISO currency code, or null when the company has not enabled it.
     *
     * A shop selling in a currency bexio does not hold cannot be booked in that currency, and
     * guessing an ID here would book the money into the wrong one.
     */
    public function getCurrencyId(?string $code): ?int
    {
        if (!$code) {
            return null;
        }

        foreach ($this->getCurrencies() as $currency) {
            if (strcasecmp((string)($currency['name'] ?? ''), $code) === 0) {
                return (int)$currency['id'];
            }
        }

        return null;
    }

    /**
     * The percentage a bexio tax charges, for the totals check.
     */
    public function getTaxRate(?int $taxId): ?float
    {
        if (!$taxId) {
            return null;
        }

        foreach ($this->getTaxes() as $tax) {
            if ((int)$tax['id'] === $taxId) {
                return (float)($tax['value'] ?? 0);
            }
        }

        return null;
    }

    /**
     * Options for a settings dropdown, with a blank first row.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{label: string, value: string}>
     */
    public function toOptions(array $rows, string $labelKey = 'name', string $valueKey = 'id'): array
    {
        $options = [['label' => Craft::t('bexy', '— none —'), 'value' => '']];

        foreach ($rows as $row) {
            if (!isset($row[$valueKey])) {
                continue;
            }

            $options[] = [
                'label' => (string)($row[$labelKey] ?? $row['display_name'] ?? $row[$valueKey]),
                'value' => (string)$row[$valueKey],
            ];
        }

        return $options;
    }

    /**
     * Tax options labelled with their percentage, because "Umsatzsteuer" three times over is not
     * a choice anybody can make.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getTaxOptions(): array
    {
        $options = [['label' => Craft::t('bexy', '— none —'), 'value' => '']];

        foreach ($this->getTaxes() as $tax) {
            $options[] = [
                'label' => sprintf('%s (%s%%)', $tax['display_name'] ?? $tax['name'] ?? $tax['id'], rtrim(rtrim(number_format((float)($tax['value'] ?? 0), 2, '.', ''), '0'), '.')),
                'value' => (string)$tax['id'],
            ];
        }

        return $options;
    }

    /**
     * Accounts labelled with their number, which is how an accountant refers to them.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getAccountOptions(): array
    {
        $options = [['label' => Craft::t('bexy', '— none —'), 'value' => '']];

        foreach ($this->getAccounts() as $account) {
            $options[] = [
                'label' => trim(sprintf('%s %s', $account['account_no'] ?? '', $account['name'] ?? '')),
                'value' => (string)$account['id'],
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getUserOptions(): array
    {
        $options = [['label' => Craft::t('bexy', '— none —'), 'value' => '']];

        foreach ($this->getUsers() as $user) {
            $name = trim(sprintf('%s %s', $user['firstname'] ?? '', $user['lastname'] ?? ''));

            $options[] = [
                'label' => $name !== '' ? $name : (string)($user['email'] ?? $user['id']),
                'value' => (string)$user['id'],
            ];
        }

        return $options;
    }

    /**
     * Forget every cached lookup. The settings screen offers this as "Refresh from bexio", for
     * when the merchant has just added the tax rate they are trying to map.
     */
    public function flush(): void
    {
        $cache = Craft::$app->getCache();

        foreach ([
            'taxes', 'accounts', 'users', 'currencies', 'units',
            'languages', 'paymentTypes', 'bankAccounts', 'countries',
        ] as $key) {
            $cache->delete(self::CACHE_PREFIX . $key);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $key, string $path, array $query = []): array
    {
        $cache = Craft::$app->getCache();
        $cacheKey = self::CACHE_PREFIX . $key;
        $cached = $cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        if (!Plugin::getInstance()->getAuth()->isConnected()) {
            return [];
        }

        try {
            $rows = Plugin::getInstance()->getApi()->get($path, $query);
        } catch (BexioApiException) {
            // A lookup failure must not take the settings screen down with it. An empty dropdown
            // with a "refresh" button beats a 500.
            return [];
        }

        $rows = array_values(array_filter($rows, 'is_array'));
        $cache->set($cacheKey, $rows, self::TTL);

        return $rows;
    }
}
