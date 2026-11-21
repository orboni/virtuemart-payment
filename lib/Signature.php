<?php

/**
 * Orboni for VirtueMart.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */

namespace Orboni\VirtueMart;

defined('_JEXEC') or exit;

/**
 * `Webhook-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">`, the
 * scheme Stripe uses. After a secret is rolled Orboni signs with both for a
 * while, so any `v1` may match.
 */
class Signature
{
    /** Seconds a signature stays good: an old event replayed later is refused. */
    const TOLERANCE = 300;

    public static function verify(string $secret, string $body, string $header, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair, " \t"), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]][] = $kv[1];
            }
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $now = $now === null ? time() : $now;
        if ($timestamp <= 0 || abs($now - $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach (isset($parts['v1']) ? $parts['v1'] : [] as $candidate) {
            if (hash_equals($expected, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key in the cron address, made from the secret key so that nobody
     * without it can make the store call Orboni. A new secret key makes a
     * new address: the payment method's settings show the current one.
     */
    public static function cronKey(string $secret): string
    {
        return $secret === '' ? '' : substr(hash_hmac('sha256', 'virtuemart-cron', $secret), 0, 32);
    }
}
