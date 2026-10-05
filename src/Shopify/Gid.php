<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

/**
 * Shopify global ids ("gid://shopify/ProductVariant/123") and the numeric
 * ids webhooks carry ("123").
 */
final class Gid
{
    public static function make(string $type, string|int $id): string
    {
        $id = (string) $id;

        return str_starts_with($id, 'gid://') ? $id : "gid://shopify/{$type}/{$id}";
    }

    /**
     * The numeric id of a gid (or the value itself when it is already one).
     */
    public static function id(string|int|null $gid): ?string
    {
        if ($gid === null || $gid === '') {
            return null;
        }

        $gid = (string) $gid;

        if (! str_starts_with($gid, 'gid://')) {
            return $gid;
        }

        $tail = substr($gid, (int) strrpos($gid, '/') + 1);

        // Drop a query string some gids carry (?inventory_item_id=...).
        return explode('?', $tail)[0];
    }
}
