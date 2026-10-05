<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Webhooks;

/**
 * HMAC-SHA256 webhook signatures, compared in constant time.
 *
 * Shopify and WooCommerce send base64(hmac_sha256(raw body, secret));
 * others send hex. Verify the RAW request body: a re-encoded JSON body
 * never matches.
 */
final class HmacSignature
{
    public const BASE64 = 'base64';

    public const HEX = 'hex';

    public static function sign(string $body, string $secret, string $encoding = self::BASE64): string
    {
        $raw = hash_hmac('sha256', $body, $secret, true);

        return $encoding === self::HEX ? bin2hex($raw) : base64_encode($raw);
    }

    public static function matches(string $body, ?string $secret, ?string $given, string $encoding = self::BASE64): bool
    {
        if ($secret === null || $secret === '' || $given === null || trim($given) === '') {
            return false;
        }

        return hash_equals(self::sign($body, $secret, $encoding), trim($given));
    }
}
