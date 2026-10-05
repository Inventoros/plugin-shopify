<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\Shopify\Console\PruneActivityCommand;
use Inventoros\Plugins\Shopify\Http\Controllers\SettingsController;
use Inventoros\Plugins\Shopify\Jobs\RecordActivity;
use Inventoros\Plugins\Shopify\Support\Permissions;
use Inventoros\Plugins\Shopify\Support\Settings;

/**
 * Everything the plugin registers with Inventoros, in one place.
 *
 * Called once per application by the closure Plugin.php returns. Nothing here
 * may query the database at registration time: this runs on every request,
 * and before the installer has created any tables.
 */
final class ShopifyPlugin
{
    public const SLUG = 'shopify';

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        private readonly string $slug,
        private readonly array $manifest,
        private readonly string $path,
    ) {}

    public function register(): void
    {
        $this->registerTranslations();
        $this->registerPermissions();
        $this->registerRoutes();
        $this->registerPages();
        $this->registerNavigation();
        $this->registerHooks();
        $this->registerCommands();
        $this->registerSchedule();
    }

    /**
     * Server-side strings (flash messages, page titles) live in
     * lang/{locale}/messages.php and are read as __('shopify::messages.key').
     */
    private function registerTranslations(): void
    {
        app('translator')->addNamespace(self::SLUG, $this->path.'/lang');
    }

    /**
     * Plugin permissions, when the running Inventoros can register them.
     * Older cores fall back to an existing core permission (see Permissions).
     */
    private function registerPermissions(): void
    {
        if (! Permissions::canRegister()) {
            return;
        }

        foreach (Permissions::definitions() as $name => [$label, $description]) {
            register_permission($name, $label, $description, 'Shopify');
        }
    }

    /**
     * Write routes. Reads are plugin pages (register_page); writes are plain
     * Laravel routes in the `web` group, so they get the session, CSRF,
     * two-factor and locale middleware like every core form. They live under
     * /shopify/actions so they never share a URI with a plugin page (a page
     * whose URI is already routed, for any method, is skipped by core).
     */
    private function registerRoutes(): void
    {
        Route::middleware(['web', 'auth'])
            ->prefix(self::SLUG.'/actions')
            ->name(self::SLUG.'.')
            ->group(function (): void {
                Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
            });
    }

    private function registerPages(): void
    {
        register_page(self::SLUG.'.settings', 'Plugin::'.self::SLUG.'/Settings', [
            'uri' => self::SLUG.'/settings',
            'title' => 'Shopify',
            'permission' => Permissions::manage(),
            'props' => fn ($request, $user) => SettingsController::pageProps($user),
        ]);
    }

    private function registerNavigation(): void
    {
        register_menu_item([
            'label' => 'Shopify',
            'route' => self::SLUG.'.settings',
            'permission' => Permissions::manage(),
            'position' => 900,
        ]);
    }

    /**
     * React to core events. The work itself is queued: a hook runs inside the
     * request (or job) that fired it and must stay cheap.
     */
    private function registerHooks(): void
    {
        add_action('low_stock_alert', static function ($product): void {
            $organizationId = (int) $product->organization_id;

            if (! Settings::for($organizationId)->get('log_low_stock')) {
                return;
            }

            RecordActivity::dispatch($organizationId, 'low_stock', (string) $product->sku);
        });
    }

    private function registerCommands(): void
    {
        if (! app()->runningInConsole()) {
            return;
        }

        Artisan::starting(static function (Artisan $artisan): void {
            $artisan->resolveCommands(self::commands());
        });
    }

    /**
     * @return array<int, class-string>
     */
    public static function commands(): array
    {
        return [PruneActivityCommand::class];
    }

    /**
     * Scheduled work runs from the core scheduler (cron on cPanel, the
     * scheduler container in Docker). The Schedule is only built in console
     * processes, so this costs nothing on web requests.
     */
    private function registerSchedule(): void
    {
        $define = static function (Schedule $schedule): void {
            $schedule->command('shopify:prune-activity')
                ->dailyAt('04:10')
                ->withoutOverlapping();
        };

        if (app()->resolved(Schedule::class)) {
            $define(app(Schedule::class));

            return;
        }

        app()->afterResolving(Schedule::class, $define);
    }
}
