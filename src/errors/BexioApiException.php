<?php

namespace justinholtweb\bexy\errors;

use RuntimeException;
use Throwable;

/**
 * A call to bexio that did not come back the way it should have.
 */
class BexioApiException extends RuntimeException
{
    public int $statusCode;

    /** @var array<string, mixed> Decoded error body, when bexio sent one. */
    public array $body;

    public string $endpoint;

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        string $endpoint = '',
        array $body = [],
        ?Throwable $previous = null,
    ) {
        $this->statusCode = $statusCode;
        $this->endpoint = $endpoint;
        $this->body = $body;

        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Whether retrying the identical request could plausibly work.
     */
    public function getIsTransient(): bool
    {
        return $this->statusCode === 0
            || $this->statusCode === 429
            || $this->statusCode >= 500;
    }

    /**
     * bexio's validation errors, flattened into readable lines.
     *
     * @return string[]
     */
    public function getValidationErrors(): array
    {
        $errors = $this->body['errors'] ?? null;

        if (!is_array($errors)) {
            return [];
        }

        $lines = [];

        foreach ($errors as $field => $messages) {
            foreach ((array)$messages as $message) {
                $lines[] = is_string($field) ? sprintf('%s: %s', $field, $message) : (string)$message;
            }
        }

        return $lines;
    }
}
