<?php

/**
 * Orboni for VirtueMart.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */

namespace Orboni\VirtueMart;

defined('_JEXEC') or exit;

use Joomla\CMS\Http\HttpFactory;
use Joomla\Registry\Registry;

/**
 * Orboni's API, as much of it as a store needs: make a checkout session, read
 * one back. `Authorization: Bearer sk_…`, JSON both ways, over Joomla's own
 * HTTP client (its transports and the site's proxy settings).
 */
class Api
{
    const VERSION = '1.0.1';

    const SESSION_ID = '/^cs_(live|test)_[A-Za-z0-9]+$/';

    const SECRET_KEY = '/^sk_(live|test)_[A-Za-z0-9]+$/';

    const WEBHOOK_SECRET = '/^whsec_[A-Za-z0-9]+$/';

    /** Seconds Orboni's own "updating, retry" answer may make a buyer wait, once. */
    const MAX_RETRY_AFTER = 5;

    /** @var string */
    private $secret;

    /** @var string */
    private $base;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
        // Another Orboni only for trying the plugin out (ORBONI_API_URL in the environment).
        $env = getenv('ORBONI_API_URL');
        $this->base = rtrim(is_string($env) && $env !== '' ? $env : 'https://orboni.com', '/');
    }

    /**
     * A new checkout session. The key makes a retry return the session the
     * first try made instead of a second one.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function createSession(array $body, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/checkout/sessions', $body, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function getSession(string $id): array
    {
        if (preg_match(self::SESSION_ID, $id) !== 1) {
            throw new ApiException('Not an Orboni checkout session id.', 'bad_session_id', 0);
        }

        return $this->request('GET', '/v1/checkout/sessions/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->secret,
            'Accept' => 'application/json',
            'User-Agent' => 'orboni-virtuemart/'.self::VERSION.'; Joomla/'.(defined('JVERSION') ? JVERSION : '?')
                .(class_exists('vmVersion') && isset(\vmVersion::$RELEASE) ? '; VirtueMart/'.\vmVersion::$RELEASE : ''),
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        // A GET, or a POST under an idempotency key, can be sent twice safely.
        $retryable = $method === 'GET' || $idempotencyKey !== null;

        $answer = null;
        for ($try = 0; $try < 2; $try++) {
            $answer = $this->send($method, $this->base.$path, $headers, $body === null ? null : (string) json_encode($body));
            if ($answer['status'] === 0) {
                if ($retryable && $try === 0) {
                    continue;
                }

                throw new ApiException('Orboni could not be reached: '.$answer['error'], 'connection_error', 0);
            }
            // Orboni's own deploy: a few seconds' "503, retry after".
            if ($answer['status'] === 503 && $retryable && $try === 0) {
                $wait = $answer['retry_after'] > 0 ? $answer['retry_after'] : 1;
                sleep(min($wait, self::MAX_RETRY_AFTER));

                continue;
            }

            break;
        }

        return self::answer($answer);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, retry_after: int, body: string, error: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        // No redirects followed: Orboni's API never sends one, and a key must not follow it elsewhere.
        $http = HttpFactory::getHttp(new Registry(['follow_location' => false]));

        try {
            $response = $method === 'POST'
                ? $http->post($url, (string) $body, $headers, 20)
                : $http->get($url, $headers, 20);
        } catch (\Throwable $e) {
            return ['status' => 0, 'retry_after' => 0, 'body' => '', 'error' => $e->getMessage()];
        }

        return [
            'status' => (int) $response->getStatusCode(),
            'retry_after' => (int) $response->getHeaderLine('Retry-After'),
            'body' => (string) $response->getBody(),
            'error' => '',
        ];
    }

    /**
     * @param  array{status: int, retry_after: int, body: string, error: string}|null  $answer
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private static function answer(?array $answer = null): array
    {
        if ($answer === null) {
            throw new ApiException('Orboni could not be reached.', 'connection_error', 0);
        }
        $data = json_decode($answer['body'], true);
        if ($answer['status'] >= 200 && $answer['status'] < 300 && is_array($data)) {
            return $data;
        }

        $error = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];

        throw new ApiException(
            isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Orboni answered HTTP '.$answer['status'].'.',
            isset($error['type']) && is_string($error['type']) ? $error['type'] : 'http_'.$answer['status'],
            $answer['status'],
            isset($error['param']) && is_string($error['param']) ? $error['param'] : ''
        );
    }
}
