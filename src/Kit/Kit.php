<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit;

/**
 * The integration kit's identity inside this plugin.
 *
 * The kit is copied, not shared: every integration plugin carries its own
 * namespaced copy (see README.md in this folder). These constants are the
 * only plugin-specific values in the kit, so a copy is renamed by changing
 * them, the namespace, and the table names in the kit migration.
 */
final class Kit
{
    /** The kit release this copy was taken from. */
    public const VERSION = '1.0.0';

    /** The owning plugin's slug. */
    public const SLUG = 'shopify';

    /** Every kit table starts with this (the plugin's table prefix). */
    public const TABLE_PREFIX = 'plg_shopify_';

    /** Hooks the kit fires are named {HOOK_PREFIX}{event}, e.g. shopify_sync_failed. */
    public const HOOK_PREFIX = 'shopify_';

    public static function table(string $name): string
    {
        return self::TABLE_PREFIX.$name;
    }

    /**
     * A cache, lock or rate-limiter key in the plugin's namespace:
     * plg:{slug}:{parts}.
     */
    public static function key(string|int ...$parts): string
    {
        return 'plg:'.self::SLUG.':'.implode(':', $parts);
    }

    /**
     * Fire one of the kit's own hooks (sync_failed, ...) for listeners in the
     * plugin, or in other plugins that integrate with it.
     */
    public static function fire(string $event, mixed ...$args): void
    {
        if (function_exists('do_action')) {
            do_action(self::HOOK_PREFIX.$event, ...$args);
        }
    }
}
