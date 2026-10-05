<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Starter\Support;

/**
 * The plugin's permissions.
 *
 * Plugin permissions are named "{slug}.{ability}", so they never collide with
 * a core permission (core names never contain a dot). When the running
 * Inventoros cannot register plugin permissions (no register_permission()),
 * each ability falls back to the closest core permission, so the plugin stays
 * usable and never grants more than core already does.
 */
final class Permissions
{
    public const MANAGE = 'starter.manage';

    /**
     * Core permission used for MANAGE when plugin permissions are unavailable.
     */
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
            self::MANAGE => ['Manage Starter', 'Can change the Starter plugin settings'],
        ];
    }

    public static function manage(): string
    {
        return self::canRegister() ? self::MANAGE : self::MANAGE_FALLBACK;
    }
}
