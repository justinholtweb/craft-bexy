<?php

namespace justinholtweb\bexy\models;

use craft\base\Model;
use DateTimeInterface;

/**
 * One line of the connection log.
 */
class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;
    public string $action = '';
    public string $level = self::LEVEL_INFO;
    public ?string $method = null;
    public ?string $endpoint = null;
    public ?int $statusCode = null;
    public ?int $durationMs = null;
    public ?int $orderId = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public ?DateTimeInterface $dateCreated = null;

    public function getIsError(): bool
    {
        return $this->level === self::LEVEL_ERROR;
    }

    /**
     * Craft's status-dot colour for this entry.
     */
    public function getStatusColor(): string
    {
        return match ($this->level) {
            self::LEVEL_ERROR => 'red',
            self::LEVEL_WARNING => 'orange',
            default => 'green',
        };
    }

    /**
     * Pretty-printed JSON if the body is JSON, the body untouched if it is not.
     */
    public function formatted(?string $body): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $body;
        }

        return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
