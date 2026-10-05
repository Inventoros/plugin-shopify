<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Support;

use App\Models\User;
use App\Services\PluginUIService;

/**
 * The plugin's permissions.
 *
 *   shopify.view    see connections, health, mappings, history and errors
 *   shopify.manage  connect stores, change settings and mappings, start
 *                   syncs, retry or dismiss failed work
 *
 * On a core without register_permission() they fall back to view_settings
 * and edit_settings, so the plugin never grants more than core already
 * does.
 */
final class Permissions
{
    public const VIEW = 'shopify.view';

    public const MANAGE = 'shopify.manage';

    public const VIEW_FALLBACK = 'view_settings';

    public const MANAGE_FALLBACK = 'edit_settings';

    public static function canRegister(): bool
    {
        return function_exists('register_permission');
    }

    /**
     * @return array<string, array{0: string, 1: string}> name => [label, description]
     */
    public static function definitions(): array
    {
        return [
            self::VIEW => ['View Shopify sync', 'See Shopify connections, sync health, mappings, history and errors'],
            self::MANAGE => ['Manage Shopify sync', 'Connect Shopify stores, change sync settings and mappings, start syncs and retry failed work'],
        ];
    }

    /**
     * Any of these opens the plugin's pages (managers can always view).
     *
     * @return array<int, string>|string
     */
    public static function view(): array|string
    {
        return self::canRegister() ? [self::VIEW, self::MANAGE] : [self::VIEW_FALLBACK, self::MANAGE_FALLBACK];
    }

    public static function manage(): string
    {
        return self::canRegister() ? self::MANAGE : self::MANAGE_FALLBACK;
    }

    public static function canView(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        foreach ((array) self::view() as $permission) {
            if (PluginUIService::allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public static function canManage(?User $user): bool
    {
        return $user !== null && PluginUIService::allows($user, self::manage());
    }
}
