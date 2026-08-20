<?php

namespace justinholtweb\bexy\models;

use craft\base\Model;

/**
 * A built bexio document body, plus what Bexy worked out while building it.
 *
 * The warnings matter as much as the body. A payload that bexio will accept happily can still be
 * the wrong invoice — a line with no tax mapped, a currency bexio does not hold, a total that does
 * not match the order. Those are all things a merchant can fix, and none of them are things bexio
 * will complain about.
 */
class DocumentPayload extends Model
{
    /** @var array<string, mixed> The JSON body, exactly as it will be sent. */
    public array $body = [];

    /** @var string `invoice` or `order`. */
    public string $documentType = 'invoice';

    /** @var float What Commerce says the order comes to. */
    public float $orderTotal = 0.0;

    /** @var float What bexio will compute from these positions. */
    public float $documentTotal = 0.0;

    /** @var float Net of the positions, before tax. */
    public float $netTotal = 0.0;

    /** @var float Tax bexio will add, or that is already inside the prices. */
    public float $taxTotal = 0.0;

    /** @var float `orderTotal` minus `documentTotal`, before any rounding position was added. */
    public float $delta = 0.0;

    /** @var bool Whether a rounding position was appended to close `delta`. */
    public bool $hasRoundingPosition = false;

    /** @var string[] Things the merchant should know about this document. */
    public array $warnings = [];

    /** @var int|null The bexio contact this document is addressed to. */
    public ?int $contactId = null;

    public function addWarning(string $warning): void
    {
        if (!in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    public function getHasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * The body as it appears in the CP preview and in `bexy/sync/preview`.
     */
    public function toJson(): string
    {
        return (string)json_encode($this->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPositions(): array
    {
        return $this->body['positions'] ?? [];
    }
}
