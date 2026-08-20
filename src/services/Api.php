<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\models\Settings;
use justinholtweb\bexy\Plugin;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Every request to bexio goes through here.
 *
 * Which means the token, the rate limit, the retry policy, the redaction and the log entry are all
 * decided once. A service that wants an invoice asks for an invoice.
 */
class Api extends Component
{
    public const BASE_URL = 'https://api.bexio.com';

    /** Attempts, in total, for a request that keeps coming back retryable. */
    public const MAX_ATTEMPTS = 3;

    /** Longest Bexy will ever sit waiting out a rate limit inside a web request. */
    public const MAX_WEB_WAIT = 3;

    /** Longest it will wait in a console command or a queue job, where nobody is watching. */
    public const MAX_BACKGROUND_WAIT = 60;

    /** Header names whose values never reach the log. */
    private const REDACTED_HEADERS = ['authorization', 'cookie', 'set-cookie'];

    private ?Client $_client = null;

    /** @var array{limit: int|null, remaining: int|null, reset: int|null} */
    private array $_rateLimit = ['limit' => null, 'remaining' => null, 'reset' => null];

    /**
     * @param array<string, mixed> $query
     * @return array<mixed>
     * @throws BexioApiException
     */
    public function get(string $path, array $query = [], ?int $orderId = null): array
    {
        return $this->request('GET', $path, null, $query, $orderId);
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>
     * @throws BexioApiException
     */
    public function post(string $path, array $body = [], ?int $orderId = null): array
    {
        return $this->request('POST', $path, $body, [], $orderId);
    }

    /**
     * @return array<mixed>
     * @throws BexioApiException
     */
    public function delete(string $path, ?int $orderId = null): array
    {
        return $this->request('DELETE', $path, null, [], $orderId);
    }

    /**
     * bexio's `/search` endpoints: a POST carrying a list of `{field, value, criteria}` filters.
     *
     * @param array<int, array{field: string, value: string, criteria?: string}> $criteria
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     * @throws BexioApiException
     */
    public function search(string $path, array $criteria, array $query = []): array
    {
        $result = $this->request('POST', rtrim($path, '/') . '/search', $criteria, $query);

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * The rate-limit headers from the most recent response.
     *
     * @return array{limit: int|null, remaining: int|null, reset: int|null}
     */
    public function getRateLimit(): array
    {
        return $this->_rateLimit;
    }

    /**
     * A cheap round-trip that proves the credentials work, for the settings screen.
     *
     * @return array{ok: bool, message: string, company: string|null, user: string|null}
     */
    public function testConnection(): array
    {
        try {
            $me = $this->get('/3.0/users/me');
        } catch (BexioApiException $e) {
            return [
                'ok' => false,
                'message' => $this->explain($e),
                'company' => null,
                'user' => null,
            ];
        }

        $name = trim(($me['firstname'] ?? '') . ' ' . ($me['lastname'] ?? ''));

        return [
            'ok' => true,
            'message' => Craft::t('bexy', 'Connected.'),
            'company' => Plugin::getInstance()->getAuth()->getTokenSet()?->companyName,
            'user' => $name !== '' ? $name : ($me['email'] ?? null),
        ];
    }

    /**
     * A bexio failure in words a merchant can act on.
     */
    public function explain(BexioApiException $e): string
    {
        $validation = $e->getValidationErrors();

        if ($validation) {
            return Craft::t('bexy', 'bexio rejected the data: {errors}', [
                'errors' => implode('; ', array_slice($validation, 0, 5)),
            ]);
        }

        return match ($e->statusCode) {
            0 => Craft::t('bexy', 'bexio could not be reached: {message}', ['message' => $e->getMessage()]),
            401 => Craft::t('bexy', 'bexio refused the credentials. Reconnect, or issue a new access token.'),
            403 => Craft::t('bexy', 'The connected bexio user is missing a permission, or the app is missing a scope, for {endpoint}.', ['endpoint' => $e->endpoint]),
            404 => Craft::t('bexy', 'bexio has no record at {endpoint}.', ['endpoint' => $e->endpoint]),
            429 => Craft::t('bexy', 'bexio’s rate limit was hit and stayed hit. Try again shortly.'),
            default => $e->getMessage(),
        };
    }

    /**
     * @param array<mixed>|null $body
     * @param array<string, mixed> $query
     * @return array<mixed>
     * @throws BexioApiException
     */
    private function request(
        string $method,
        string $path,
        ?array $body,
        array $query,
        ?int $orderId = null,
    ): array {
        $endpoint = '/' . ltrim($path, '/');
        $attempt = 0;
        $refreshed = false;

        while (true) {
            $attempt++;
            $token = Plugin::getInstance()->getAuth()->getAccessToken();

            if (!$token) {
                throw new BexioApiException(
                    Craft::t('bexy', 'Bexy is not connected to bexio.'),
                    401,
                    $endpoint,
                );
            }

            $started = microtime(true);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
                'http_errors' => false,
            ];

            if ($body !== null) {
                $options['json'] = $body;
            }

            try {
                $response = $this->client()->request($method, self::BASE_URL . $endpoint, $options);
            } catch (ConnectException|RequestException $e) {
                $exception = new BexioApiException($e->getMessage(), 0, $endpoint, [], $e);
                $this->log($method, $endpoint, null, $started, $body, null, $orderId, $e->getMessage());

                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->wait(min(2 ** $attempt, $this->maxWait()));
                    continue;
                }

                throw $exception;
            } catch (Throwable $e) {
                $this->log($method, $endpoint, null, $started, $body, null, $orderId, $e->getMessage());

                throw new BexioApiException($e->getMessage(), 0, $endpoint, [], $e);
            }

            $status = $response->getStatusCode();
            $raw = (string)$response->getBody();
            $this->captureRateLimit($response);
            $this->log($method, $endpoint, $status, $started, $body, $raw, $orderId);

            if ($status >= 200 && $status < 300) {
                if ($raw === '') {
                    return [];
                }

                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : ['result' => $decoded];
            }

            // An expired access token looks exactly like a wrong one. Try a refresh, once — a
            // refresh loop against genuinely bad credentials would hammer bexio's IdP.
            if ($status === 401 && !$refreshed && $this->settings()->authMode === Settings::AUTH_OAUTH) {
                $refreshed = true;

                if (Plugin::getInstance()->getAuth()->refresh()) {
                    continue;
                }
            }

            if ($status === 429 && $attempt < self::MAX_ATTEMPTS) {
                $this->wait(min($this->_rateLimit['reset'] ?? 2 ** $attempt, $this->maxWait()));
                continue;
            }

            if ($status >= 500 && $attempt < self::MAX_ATTEMPTS) {
                $this->wait(min(2 ** $attempt, $this->maxWait()));
                continue;
            }

            $decoded = json_decode($raw, true);
            $decoded = is_array($decoded) ? $decoded : [];

            throw new BexioApiException(
                $decoded['message'] ?? sprintf('bexio returned HTTP %d for %s.', $status, $endpoint),
                $status,
                $endpoint,
                $decoded,
            );
        }
    }

    private function client(): Client
    {
        return $this->_client ??= Craft::createGuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    private function captureRateLimit(ResponseInterface $response): void
    {
        foreach (['limit' => 'RateLimit-Limit', 'remaining' => 'RateLimit-Remaining', 'reset' => 'RateLimit-Reset'] as $key => $header) {
            $value = $response->getHeaderLine($header);
            $this->_rateLimit[$key] = $value === '' ? null : (int)$value;
        }
    }

    /**
     * How long this process is allowed to block.
     *
     * A queue worker can afford to wait out a rate limit. A web request cannot: the merchant is
     * looking at a spinner, and PHP's own time limit is the next thing to run out.
     */
    private function maxWait(): int
    {
        return Craft::$app->getRequest()->getIsConsoleRequest()
            ? self::MAX_BACKGROUND_WAIT
            : self::MAX_WEB_WAIT;
    }

    private function wait(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * @param array<mixed>|null $body
     */
    private function log(
        string $method,
        string $endpoint,
        ?int $status,
        float $started,
        ?array $body,
        ?string $response,
        ?int $orderId,
        ?string $message = null,
    ): void {
        $level = $message !== null || $status === null || $status >= 400 ? 'error' : 'info';

        Plugin::getInstance()->getLog()->write($this->actionFor($method, $endpoint), [
            'level' => $level,
            'method' => $method,
            'endpoint' => $endpoint,
            'statusCode' => $status,
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
            'orderId' => $orderId,
            'summary' => sprintf('%s %s', $method, $endpoint),
            'message' => $message,
            'request' => $body !== null ? $this->encode($this->redact($body)) : null,
            'response' => $response,
        ]);
    }

    /**
     * A stable, greppable name for the log index: `kb_invoice.post`, `contact.search`.
     */
    private function actionFor(string $method, string $endpoint): string
    {
        $parts = array_values(array_filter(explode('/', $endpoint)));
        // Drop the version segment, and any numeric ID, so retries of the same call group.
        $parts = array_values(array_filter(
            array_slice($parts, 1),
            static fn(string $part): bool => !is_numeric($part),
        ));

        $resource = implode('.', array_slice($parts, 0, 2)) ?: 'api';

        return mb_substr($resource . '.' . strtolower($method), 0, 64);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
                continue;
            }

            if (is_string($key) && in_array(strtolower($key), self::REDACTED_HEADERS, true)) {
                $data[$key] = '***';
                continue;
            }

            if (is_string($key) && preg_match('/(token|secret|password|authorization)/i', $key)) {
                $data[$key] = '***';
            }
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private function encode(array $data): string
    {
        return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
