<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\Address as AddressElement;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\bexy\db\Table;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\helpers\Address;
use justinholtweb\bexy\models\Settings;
use justinholtweb\bexy\Plugin;

/**
 * Matching a Commerce customer to a bexio contact, and creating one when there is no match.
 *
 * The failure mode this exists to prevent is a bexio contact list with the same customer in it
 * eleven times, one per order — which is what happens when every push creates a contact.
 */
class Contacts extends Component
{
    /** bexio files companies and people separately, and the fields mean different things. */
    public const TYPE_COMPANY = 1;
    public const TYPE_PERSON = 2;

    /**
     * The bexio contact for an order, creating one if that is allowed.
     *
     * Three places are checked in order, cheapest first: Bexy's own map, then bexio's contact
     * list by email, then a fresh contact.
     *
     * @throws BexioApiException
     */
    public function resolveForOrder(Order $order): ?int
    {
        $email = trim((string)$order->getEmail());

        if ($email === '') {
            return null;
        }

        $mapped = $this->getMappedContactId($email);

        if ($mapped !== null) {
            return $mapped;
        }

        $found = $this->findByEmail($email);

        if ($found !== null) {
            $this->remember($email, $found, $order, 'matched');

            return $found;
        }

        if (!Plugin::getInstance()->getSettings()->createContacts) {
            return null;
        }

        $created = $this->create($order);

        if ($created !== null) {
            $this->remember($email, $created, $order, 'created');
        }

        return $created;
    }

    /**
     * bexio's contact ID for an email address, from Bexy's map.
     */
    public function getMappedContactId(string $email): ?int
    {
        $id = (new Query())
            ->select(['bexioContactId'])
            ->from(Table::CONTACTS)
            ->where(['email' => $this->normalizeEmail($email)])
            ->scalar();

        return $id ? (int)$id : null;
    }

    /**
     * Search bexio for a contact with this email.
     *
     * Both `mail` and `mail_second` are checked, because a shop's customer may well be filed in
     * bexio under a second address.
     *
     * @throws BexioApiException
     */
    public function findByEmail(string $email): ?int
    {
        $api = Plugin::getInstance()->getApi();

        foreach (['mail', 'mail_second'] as $field) {
            $matches = $api->search('/2.0/contact', [
                ['field' => $field, 'value' => $email, 'criteria' => '='],
            ], ['limit' => 2]);

            if ($matches) {
                return (int)$matches[0]['id'];
            }
        }

        return null;
    }

    /**
     * Create the contact.
     *
     * @throws BexioApiException
     */
    public function create(Order $order): ?int
    {
        $settings = Plugin::getInstance()->getSettings();
        $userId = $this->bexioUserId();

        if (!$userId) {
            throw new BexioApiException(
                Craft::t('bexy', 'No bexio user is selected in Bexy’s settings. bexio requires one on every contact it files.'),
                0,
                '/2.0/contact',
            );
        }

        $payload = $this->buildContactPayload($order, $userId, $settings);
        $created = Plugin::getInstance()->getApi()->post('/2.0/contact', $payload, $order->id);

        return isset($created['id']) ? (int)$created['id'] : null;
    }

    /**
     * The contact body for an order. Public so the console can show it without sending it.
     *
     * @return array<string, mixed>
     */
    public function buildContactPayload(Order $order, int $userId, Settings $settings): array
    {
        $address = $order->getBillingAddress() ?? $order->getShippingAddress();
        $organization = $address?->organization ? trim((string)$address->organization) : '';
        $isCompany = $organization !== '';

        if ($isCompany) {
            $names = ['name_1' => $organization, 'name_2' => trim((string)$address?->fullName)];
        } else {
            $names = Address::splitName(
                $address?->fullName ?? $order->getCustomer()?->fullName,
                $address?->firstName,
                $address?->lastName,
            );
        }

        if (trim($names['name_1']) === '') {
            // bexio will not file a nameless contact, and an order always has an email.
            $names['name_1'] = (string)$order->getEmail();
        }

        $street = Address::splitStreet($address?->addressLine1);

        $payload = [
            'contact_type_id' => $isCompany ? self::TYPE_COMPANY : self::TYPE_PERSON,
            'name_1' => mb_substr($names['name_1'], 0, 255),
            'name_2' => mb_substr($names['name_2'], 0, 255) ?: null,
            'mail' => $order->getEmail(),
            'user_id' => $userId,
            'owner_id' => $userId,
            'street_name' => $street['street_name'] ?: null,
            'house_number' => $street['house_number'] ?: null,
            'address_addition' => $address?->addressLine2 ?: null,
            'postcode' => $address?->postalCode ?: null,
            'city' => $address?->locality ?: null,
            'country_id' => Plugin::getInstance()->getMeta()->getCountryId($address?->countryCode),
        ];

        $phone = $this->phoneFrom($address);

        if ($phone !== null) {
            $payload['phone_fixed'] = $phone;
        }

        if ($settings->languageId) {
            $payload['language_id'] = $settings->languageId;
        }

        $groups = trim(preg_replace('/\s+/', '', $settings->contactGroupIds) ?? '');

        if ($groups !== '') {
            $payload['contact_group_ids'] = $groups;
        }

        return array_filter($payload, static fn($value): bool => $value !== null && $value !== '');
    }

    /**
     * Push the order's address onto an existing bexio contact.
     *
     * Off by default, and deliberately so: bexio's contact record is the accountant's, and a
     * customer who once typed a delivery address into the shop should not be able to overwrite
     * the billing address the books have been using for three years.
     *
     * @throws BexioApiException
     */
    public function update(int $contactId, Order $order): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $userId = $this->bexioUserId();

        if (!$settings->updateContacts || !$userId) {
            return;
        }

        Plugin::getInstance()->getApi()->post(
            '/2.0/contact/' . $contactId,
            $this->buildContactPayload($order, $userId, $settings),
            $order->id,
        );
    }

    /**
     * Record the email → bexio contact mapping.
     */
    public function remember(string $email, int $bexioContactId, ?Order $order = null, string $source = 'matched'): void
    {
        $now = Db::prepareDateForDb(new DateTime());
        $email = $this->normalizeEmail($email);

        $existingId = (new Query())
            ->select(['id'])
            ->from(Table::CONTACTS)
            ->where(['email' => $email])
            ->scalar();

        $db = Craft::$app->getDb();
        $values = [
            'bexioContactId' => $bexioContactId,
            'customerId' => $order?->getCustomer()?->id,
            'name' => $order?->getBillingAddress()?->fullName,
            'source' => $source,
            'dateUpdated' => $now,
        ];

        if ($existingId) {
            $db->createCommand()->update(Table::CONTACTS, $values, ['id' => $existingId])->execute();

            return;
        }

        $db->createCommand()->insert(Table::CONTACTS, $values + [
            'email' => $email,
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    /**
     * Drop a mapping, so the next push looks the contact up again. Used when bexio 404s a contact
     * Bexy still has on file — someone deleted it there.
     */
    public function forget(int $bexioContactId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Table::CONTACTS, ['bexioContactId' => $bexioContactId])
            ->execute();
    }

    public function getMappingCount(): int
    {
        return (int)(new Query())->from(Table::CONTACTS)->count('[[id]]');
    }

    /**
     * The bexio user everything is filed under, falling back to whoever authorised the connection.
     */
    public function bexioUserId(): ?int
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->bexioUserId) {
            return $settings->bexioUserId;
        }

        return Plugin::getInstance()->getAuth()->getTokenSet()?->bexioUserId;
    }

    private function phoneFrom(?AddressElement $address): ?string
    {
        if (!$address) {
            return null;
        }

        // Commerce stores a phone number as a custom field when the shop asks for one, and the
        // handle is the merchant's to choose, so only the conventional ones are tried.
        foreach (['phone', 'phoneNumber', 'telephone'] as $handle) {
            try {
                $value = $address->getFieldValue($handle);
            } catch (\Throwable) {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 255);
            }
        }

        return null;
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
