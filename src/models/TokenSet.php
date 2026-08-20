<?php

namespace justinholtweb\bexy\models;

use craft\base\Model;
use DateTime;
use DateTimeInterface;

/**
 * The stored bexio connection.
 */
class TokenSet extends Model
{
    public ?string $accessToken = null;
    public ?string $refreshToken = null;
    public ?string $scope = null;
    public ?string $companyId = null;
    public ?string $companyName = null;
    public ?string $userEmail = null;
    public ?int $bexioUserId = null;
    public ?DateTimeInterface $dateExpires = null;
    public ?DateTimeInterface $dateRefreshed = null;
    public ?DateTimeInterface $dateCreated = null;

    /**
     * Seconds of headroom before expiry at which the token is refreshed anyway.
     *
     * A token that is valid "now" can still be expired by the time a slow request reaches bexio,
     * and a 401 mid-push is far more expensive than an early refresh.
     */
    public const REFRESH_SKEW = 120;

    public function getIsExpired(): bool
    {
        if (!$this->dateExpires) {
            return false;
        }

        return $this->dateExpires->getTimestamp() - self::REFRESH_SKEW <= (new DateTime())->getTimestamp();
    }

    public function getSecondsRemaining(): ?int
    {
        if (!$this->dateExpires) {
            return null;
        }

        return max(0, $this->dateExpires->getTimestamp() - (new DateTime())->getTimestamp());
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return array_values(array_filter(explode(' ', (string)$this->scope)));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->getScopes(), true);
    }
}
