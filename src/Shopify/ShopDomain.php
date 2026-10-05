<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

/**
 * Shop domains. Only "{store}.myshopify.com" is accepted: requests then go
 * to Shopify's own hosts and never to an address a user typed (no SSRF to
 * private or loopback addresses). A custom storefront domain is not an
 * Admin API host.
 */
final class ShopDomain
{
    private const PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}\.myshopify\.com$/';

    /**
     * "https://My-Store.myshopify.com/admin" becomes "my-store.myshopify.com";
     * a bare store handle "my-store" gets the suffix.
     */
    public static function normalize(string $input): string
    {
        $value = strtolower(trim($input));
        $value = (string) preg_replace('#^https?://#', '', $value);
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        $value = rtrim($value, '.');

        if ($value !== '' && ! str_contains($value, '.')) {
            $value .= '.myshopify.com';
        }

        return $value;
    }

    public static function valid(?string $domain): bool
    {
        return is_string($domain) && preg_match(self::PATTERN, $domain) === 1;
    }
}
