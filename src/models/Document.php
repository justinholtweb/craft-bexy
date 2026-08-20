<?php

namespace justinholtweb\bexy\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\helpers\UrlHelper;
use DateTimeInterface;
use justinholtweb\bexy\services\Meta;

/**
 * The link between one Commerce order and one bexio document.
 */
class Document extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_INVOICE = 'invoice';
    public const TYPE_ORDER = 'order';

    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $storeId = null;
    public ?string $orderNumber = null;
    public string $apiReference = '';
    public string $documentType = self::TYPE_INVOICE;
    public ?int $bexioId = null;
    public ?string $bexioNumber = null;
    public ?int $bexioContactId = null;
    public ?int $bexioStatusId = null;
    public string $status = self::STATUS_PENDING;
    public bool $isIssued = false;
    public bool $isSent = false;
    public ?string $currency = null;
    public ?float $orderTotal = null;
    public ?float $documentTotal = null;
    public float $roundingDelta = 0.0;
    public float $amountPaid = 0.0;
    public ?float $amountRemaining = null;
    public int $attempts = 0;
    public ?string $lastError = null;
    public ?string $needsAttention = null;
    public ?string $payload = null;
    public ?DateTimeInterface $dateSynced = null;
    public ?DateTimeInterface $dateReconciled = null;
    public ?DateTimeInterface $dateCreated = null;
    public ?DateTimeInterface $dateUpdated = null;

    private ?Order $_order = null;

    public function getOrder(): ?Order
    {
        if ($this->_order === null && $this->orderId) {
            $this->_order = Order::find()->id($this->orderId)->status(null)->one();
        }

        return $this->_order;
    }

    public function setOrder(?Order $order): void
    {
        $this->_order = $order;
    }

    /**
     * The bexio document status in words.
     */
    public function getBexioStatusLabel(): ?string
    {
        if (!$this->bexioStatusId) {
            return null;
        }

        $map = $this->documentType === self::TYPE_ORDER ? Meta::ORDER_STATUSES : Meta::INVOICE_STATUSES;

        return $map[$this->bexioStatusId] ?? (string)$this->bexioStatusId;
    }

    /**
     * Craft's status-dot colour.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_SYNCED => $this->needsAttention ? 'orange' : 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_CANCELLED => 'grey',
            default => 'yellow',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SYNCED => Craft::t('bexy', 'Synced'),
            self::STATUS_FAILED => Craft::t('bexy', 'Failed'),
            self::STATUS_CANCELLED => Craft::t('bexy', 'Cancelled'),
            default => Craft::t('bexy', 'Pending'),
        };
    }

    /**
     * Deep link into bexio's own UI, so the merchant can go and look at the thing.
     */
    public function getBexioUrl(): ?string
    {
        if (!$this->bexioId) {
            return null;
        }

        return $this->documentType === self::TYPE_ORDER
            ? sprintf('https://office.bexio.com/index.php/kb_order/show/id/%d', $this->bexioId)
            : sprintf('https://office.bexio.com/index.php/kb_invoice/show/id/%d', $this->bexioId);
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('bexy/documents/' . $this->id);
    }

    /**
     * Whether the totals Bexy sent and the totals Commerce holds disagree.
     */
    public function getHasRoundingDelta(): bool
    {
        return abs($this->roundingDelta) > 0.000001;
    }

    public function getIsPaidInBexio(): bool
    {
        return $this->bexioStatusId === 9;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDecodedPayload(): ?array
    {
        if (!$this->payload) {
            return null;
        }

        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
