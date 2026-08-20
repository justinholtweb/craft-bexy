<?php

namespace justinholtweb\bexy\helpers;

use craft\elements\Address as AddressElement;

/**
 * Turning a Craft address into the shape bexio's contact endpoint wants.
 */
abstract class Address
{
    /**
     * Split a street line into bexio's `street_name` and `house_number`.
     *
     * bexio deprecated the single `address` field on contact writes at the end of 2025, so the
     * street and the number now have to be sent apart. Craft keeps them together in one line, as
     * every address form in the German-speaking world does, with the number last —
     * "Bahnhofstrasse 12", "Bahnhofstrasse 12a", "Bahnhofstrasse 12-14".
     *
     * When the line does not end in a number — an English-style "12 Station Road", a PO box, a
     * company name — the whole line stays in `street_name`. A wrong split is worse than none: it
     * prints a broken address on an invoice.
     *
     * @return array{street_name: string, house_number: string}
     */
    public static function splitStreet(?string $line): array
    {
        $line = trim((string)$line);

        if ($line === '') {
            return ['street_name' => '', 'house_number' => ''];
        }

        if (preg_match('/^(.*?[^\s\d])\s+(\d+\s*[-\/]?\s*\d*\s*[a-zA-Z]?)$/u', $line, $matches)) {
            return [
                'street_name' => trim($matches[1]),
                'house_number' => trim($matches[2]),
            ];
        }

        return ['street_name' => $line, 'house_number' => ''];
    }

    /**
     * The address block bexio prints on a document, as it prints it: one line per line, `\n`
     * separated. Used for `contact_address_manual`, which overrides whatever the contact record
     * says — so an order shipped to a different address bills to the address on the order.
     */
    public static function toBlock(?AddressElement $address, ?string $organization = null, ?string $name = null): string
    {
        if (!$address) {
            return '';
        }

        $lines = [];

        $organization ??= $address->organization;

        if ($organization) {
            $lines[] = $organization;
        }

        $name ??= $address->fullName;

        if ($name && $name !== $organization) {
            $lines[] = $name;
        }

        foreach ([$address->addressLine1, $address->addressLine2, $address->addressLine3] as $line) {
            if ($line) {
                $lines[] = $line;
            }
        }

        $cityLine = trim(sprintf('%s %s', (string)$address->postalCode, (string)$address->locality));

        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }

        return implode("\n", $lines);
    }

    /**
     * A person's name, split the way bexio files people: `name_1` is the surname.
     *
     * @return array{name_1: string, name_2: string}
     */
    public static function splitName(?string $fullName, ?string $firstName = null, ?string $lastName = null): array
    {
        $firstName = trim((string)$firstName);
        $lastName = trim((string)$lastName);

        if ($lastName !== '') {
            return ['name_1' => $lastName, 'name_2' => $firstName];
        }

        $fullName = trim((string)$fullName);

        if ($fullName === '') {
            return ['name_1' => '', 'name_2' => ''];
        }

        $parts = preg_split('/\s+/u', $fullName) ?: [$fullName];

        if (count($parts) === 1) {
            return ['name_1' => $parts[0], 'name_2' => ''];
        }

        $surname = array_pop($parts);

        return ['name_1' => $surname, 'name_2' => implode(' ', $parts)];
    }
}
