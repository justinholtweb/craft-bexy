<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\bexy\db\Table;
use justinholtweb\bexy\models\Settings;
use justinholtweb\bexy\models\TokenSet;
use justinholtweb\bexy\Plugin;
use Throwable;
use yii\base\Exception;

/**
 * The bexio connection.
 *
 * Two ways in. A Personal Access Token is one paste and works immediately — and bexio expires it
 * 60 days after it was created, silently, so a shop wired up that way stops booking every couple
 * of months. The authorization code flow is more setup and then stays connected, because the
 * refresh token renews itself on every call.
 */
class Auth extends Component
{
    public const ISSUER = 'https://auth.bexio.com/realms/bexio';
    public const AUTHORIZE_URL = self::ISSUER . '/protocol/openid-connect/auth';
    public const TOKEN_URL = self::ISSUER . '/protocol/openid-connect/token';
    public const USERINFO_URL = self::ISSUER . '/protocol/openid-connect/userinfo';

    public const SESSION_STATE = 'bexy.oauth.state';
    public const SESSION_VERIFIER = 'bexy.oauth.verifier';

    /**
     * The scopes Bexy asks for.
     *
     * bexio grants read access implicitly with every write scope, so `contact_show` and friends
     * are deliberately absent — asking for them only makes the consent screen longer.
     */
    public const SCOPES = [
        'openid',
        'profile',
        'email',
        'company_profile',
        'offline_access',
        'contact_edit',
        'kb_invoice_edit',
        'kb_order_edit',
        'article_edit',
        'bank_account_show',
    ];

    private ?TokenSet $_tokenSet = null;
    private bool $_loaded = false;

    /**
     * The redirect URL to register at developer.bexio.com.
     */
    public function getRedirectUri(): string
    {
        return UrlHelper::cpUrl('bexy/oauth/callback');
    }

    /**
     * Where to send the browser to start the authorization code flow.
     *
     * The state and the PKCE verifier are put in the session here and checked in the callback;
     * without both, anyone who can make the merchant's browser hit the callback URL can attach a
     * bexio account of their choosing to the shop.
     */
    public function getAuthorizationUrl(): string
    {
        $settings = $this->settings();
        $state = StringHelper::UUID();
        $verifier = StringHelper::randomString(64);

        $session = Craft::$app->getSession();
        $session->set(self::SESSION_STATE, $state);
        $session->set(self::SESSION_VERIFIER, $verifier);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return UrlHelper::urlWithParams(self::AUTHORIZE_URL, [
            'client_id' => $settings->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Exchange the authorization code for tokens.
     *
     * @throws Exception if the state does not match or bexio rejects the exchange.
     */
    public function handleCallback(string $code, string $state): TokenSet
    {
        $session = Craft::$app->getSession();
        $expected = (string)$session->get(self::SESSION_STATE);
        $verifier = (string)$session->get(self::SESSION_VERIFIER);
        $session->remove(self::SESSION_STATE);
        $session->remove(self::SESSION_VERIFIER);

        if ($expected === '' || !hash_equals($expected, $state)) {
            throw new Exception(Craft::t('bexy', 'The authorization state did not match. Start the connection again.'));
        }

        $settings = $this->settings();

        $token = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->getRedirectUri(),
            'client_id' => $settings->getClientId(),
            'client_secret' => $settings->getClientSecret(),
            'code_verifier' => $verifier,
        ]);

        $tokenSet = $this->storeToken($token, null);
        $this->hydrateIdentity($tokenSet);

        return $tokenSet;
    }

    /**
     * The bearer token to send, refreshing first if it is about to expire.
     *
     * Returns null when the plugin is not connected, which callers treat as "do nothing" rather
     * than as an error — a shop mid-setup should not fill its log with failures.
     */
    public function getAccessToken(): ?string
    {
        $settings = $this->settings();

        if ($settings->authMode === Settings::AUTH_PAT) {
            return $settings->getPersonalAccessToken() ?: null;
        }

        $tokenSet = $this->getTokenSet();

        if (!$tokenSet || !$tokenSet->accessToken) {
            return null;
        }

        if ($tokenSet->getIsExpired() && $tokenSet->refreshToken) {
            $refreshed = $this->refresh();

            if (!$refreshed) {
                return null;
            }

            return $refreshed->accessToken;
        }

        return $tokenSet->accessToken;
    }

    /**
     * Trade the refresh token for a new pair.
     *
     * bexio rotates refresh tokens: the response carries a new one, and reusing the old one after
     * that stops working. Storing whatever comes back is not optional.
     */
    public function refresh(): ?TokenSet
    {
        $tokenSet = $this->getTokenSet();

        if (!$tokenSet || !$tokenSet->refreshToken) {
            return null;
        }

        $settings = $this->settings();

        try {
            $token = $this->requestToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => $tokenSet->refreshToken,
                'client_id' => $settings->getClientId(),
                'client_secret' => $settings->getClientSecret(),
            ]);
        } catch (Throwable $e) {
            Plugin::getInstance()->getLog()->write('auth.refresh', [
                'level' => 'error',
                'summary' => Craft::t('bexy', 'Token refresh failed'),
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $stored = $this->storeToken($token, $tokenSet->refreshToken);

        Plugin::getInstance()->getLog()->write('auth.refresh', [
            'summary' => Craft::t('bexy', 'Access token refreshed'),
        ]);

        return $stored;
    }

    public function getTokenSet(): ?TokenSet
    {
        if ($this->_loaded) {
            return $this->_tokenSet;
        }

        $this->_loaded = true;

        $row = (new Query())
            ->from(Table::TOKENS)
            ->orderBy(['id' => SORT_ASC])
            ->one();

        if (!$row) {
            return $this->_tokenSet = null;
        }

        return $this->_tokenSet = new TokenSet([
            'accessToken' => $this->decrypt($row['accessToken']),
            'refreshToken' => $this->decrypt($row['refreshToken']),
            'scope' => $row['scope'] ?: null,
            'companyId' => $row['companyId'] ?: null,
            'companyName' => $row['companyName'] ?: null,
            'userEmail' => $row['userEmail'] ?: null,
            'bexioUserId' => $row['bexioUserId'] !== null ? (int)$row['bexioUserId'] : null,
            'dateExpires' => $row['dateExpires'] ? DateTimeHelper::toDateTime($row['dateExpires'], true) : null,
            'dateRefreshed' => $row['dateRefreshed'] ? DateTimeHelper::toDateTime($row['dateRefreshed'], true) : null,
            'dateCreated' => $row['dateCreated'] ? DateTimeHelper::toDateTime($row['dateCreated'], true) : null,
        ]);
    }

    /**
     * Whether Bexy can currently talk to bexio.
     */
    public function isConnected(): bool
    {
        $settings = $this->settings();

        if ($settings->authMode === Settings::AUTH_PAT) {
            return $settings->getPersonalAccessToken() !== '';
        }

        $tokenSet = $this->getTokenSet();

        return $tokenSet !== null && $tokenSet->accessToken !== null;
    }

    /**
     * Forget the connection. The bexio-side authorization is revoked by the merchant in bexio;
     * this only drops what Craft holds.
     */
    public function disconnect(): void
    {
        Craft::$app->getDb()->createCommand()->delete(Table::TOKENS)->execute();
        $this->_tokenSet = null;
        $this->_loaded = false;

        Plugin::getInstance()->getLog()->write('auth.disconnect', [
            'summary' => Craft::t('bexy', 'Disconnected from bexio'),
        ]);
    }

    /**
     * Fill in who and which company the token belongs to, for the settings screen.
     */
    public function hydrateIdentity(TokenSet $tokenSet): void
    {
        try {
            $client = Craft::createGuzzleClient(['timeout' => 15]);
            $response = $client->get(self::USERINFO_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $tokenSet->accessToken,
                    'Accept' => 'application/json',
                ],
            ]);

            $info = (array)json_decode((string)$response->getBody(), true);
        } catch (Throwable) {
            return;
        }

        $me = Plugin::getInstance()->getApi()->get('/3.0/users/me');

        Craft::$app->getDb()->createCommand()->update(Table::TOKENS, [
            'companyId' => isset($info['company_id']) ? (string)$info['company_id'] : null,
            'companyName' => $info['company_name'] ?? null,
            'userEmail' => $info['email'] ?? null,
            'bexioUserId' => isset($me['id']) ? (int)$me['id'] : null,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $this->tokenRowId()])->execute();

        $this->_loaded = false;
        $this->_tokenSet = null;
    }

    /**
     * @param array<string, string> $body
     * @return array<string, mixed>
     * @throws Exception
     */
    private function requestToken(array $body): array
    {
        $client = Craft::createGuzzleClient(['timeout' => 20]);

        try {
            // bexio's IdP stopped accepting these as query parameters when it moved to Keycloak;
            // they have to go in the form body.
            $response = $client->post(self::TOKEN_URL, [
                'form_params' => array_filter($body, static fn($v): bool => $v !== null && $v !== ''),
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (RequestException $e) {
            $detail = $e->getResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();

            throw new Exception(Craft::t('bexy', 'bexio rejected the token request: {detail}', [
                'detail' => mb_substr($detail, 0, 500),
            ]));
        }

        $data = json_decode((string)$response->getBody(), true);

        if (!is_array($data) || empty($data['access_token'])) {
            throw new Exception(Craft::t('bexy', 'bexio returned no access token.'));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $token
     */
    private function storeToken(array $token, ?string $fallbackRefreshToken): TokenSet
    {
        $expires = new DateTime();

        if (!empty($token['expires_in'])) {
            $expires->modify('+' . (int)$token['expires_in'] . ' seconds');
        }

        $now = Db::prepareDateForDb(new DateTime());
        $id = $this->tokenRowId();

        $values = [
            'accessToken' => $this->encrypt((string)$token['access_token']),
            'refreshToken' => $this->encrypt((string)($token['refresh_token'] ?? $fallbackRefreshToken ?? '')),
            'scope' => (string)($token['scope'] ?? implode(' ', self::SCOPES)),
            'dateExpires' => Db::prepareDateForDb($expires),
            'dateRefreshed' => $now,
            'dateUpdated' => $now,
        ];

        $db = Craft::$app->getDb();

        if ($id) {
            $db->createCommand()->update(Table::TOKENS, $values, ['id' => $id])->execute();
        } else {
            $db->createCommand()->insert(Table::TOKENS, $values + [
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $this->_loaded = false;
        $this->_tokenSet = null;

        return $this->getTokenSet();
    }

    /**
     * Tokens are stored encrypted with Craft's security key.
     *
     * A bexio refresh token is a standing key to the company's whole accounting system, and a
     * database dump — a backup, a staging clone, a support export — should not be one too.
     *
     * The ciphertext is base64'd because `Security::encryptByKey()` returns **raw binary**, and
     * putting that straight into a text column on a utf8mb4 connection fails with
     * `SQLSTATE[22007] Invalid datetime format: 1366 Incorrect string value` — an error that names
     * a datetime problem for a token column and sends you looking in the wrong place entirely.
     */
    private function encrypt(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return base64_encode(Craft::$app->getSecurity()->encryptByKey($value));
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = base64_decode($value, true);

        if ($raw === false) {
            return null;
        }

        try {
            $plain = Craft::$app->getSecurity()->decryptByKey($raw);
        } catch (Throwable) {
            // A rotated security key makes every stored token undecryptable. That is a
            // disconnection, not a crash — the merchant reconnects and gets a fresh pair.
            Craft::warning('Bexy could not decrypt its stored bexio token. The security key may have changed; reconnect to bexio.', __METHOD__);

            return null;
        }

        return $plain === false ? null : $plain;
    }

    private function tokenRowId(): ?int
    {
        $id = (new Query())->select(['id'])->from(Table::TOKENS)->orderBy(['id' => SORT_ASC])->scalar();

        return $id ? (int)$id : null;
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
