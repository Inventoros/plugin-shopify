<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\Shopify\Console\PruneCommand;
use Inventoros\Plugins\Shopify\Console\SyncCommand;
use Inventoros\Plugins\Shopify\Http\Controllers\ActionsController;
use Inventoros\Plugins\Shopify\Http\Controllers\PagesController;
use Inventoros\Plugins\Shopify\Http\Controllers\WebhookController;
use Inventoros\Plugins\Shopify\Jobs\RegisterWebhooks;
use Inventoros\Plugins\Shopify\Jobs\RunSync;
use Inventoros\Plugins\Shopify\Kit\Connections\ConnectionTester;
use Inventoros\Plugins\Shopify\Kit\Kit;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Mcp\SyncStatusTool;
use Inventoros\Plugins\Shopify\Shopify\ShopifyConnectionTester;
use Inventoros\Plugins\Shopify\Support\Events;
use Inventoros\Plugins\Shopify\Support\Listeners;
use Inventoros\Plugins\Shopify\Support\Permissions;

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
     * Prefix of every route name the plugin registers (core never uses plg.*).
     */
    public const ROUTE = 'plg.'.self::SLUG.'.';

    /**
     * URI of the plugin's pages and web routes (core never uses /p/).
     */
    public const URI = 'p/'.self::SLUG;

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
        $handler = app(ExceptionHandler::class);
        // Console renderers can decorate the HTTP handler without exposing
        // its validation-input configuration (for example Collision).
        if (method_exists($handler, 'dontFlash')) {
            $handler->dontFlash(['access_token', 'webhook_secret', 'client_id']);
        }
        $this->registerServices();
        $this->registerTranslations();
        $this->registerPermissions();
        $this->registerRoutes();
        $this->registerPages();
        $this->registerNavigation();
        $this->registerHooks();
        $this->registerWebhookEvents();
        $this->registerMcpTools();
        $this->registerCommands();
        $this->registerSchedule();
    }

    private function registerServices(): void
    {
        app()->bindIf(ConnectionTester::class, ShopifyConnectionTester::class);
    }

    /**
     * Server strings live in lang/{locale}/messages.php: __('shopify::messages.key').
     */
    private function registerTranslations(): void
    {
        app('translator')->addNamespace(self::SLUG, $this->path.'/lang');
    }

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
     * Writes under /p/shopify/actions (web group: session, CSRF, sign-in),
     * and the webhook inbox under /webhooks/plugins/shopify/{connection},
     * outside the web group: Shopify signs its requests and has no session.
     * The inbox address is the same as in 1.0.0, so the subscriptions stores
     * already registered keep delivering; only its route name changed.
     */
    private function registerRoutes(): void
    {
        Route::middleware(['web', 'auth', 'throttle:120,1'])
            ->prefix(self::URI.'/actions')
            ->name(self::ROUTE.'actions.')
            ->group(function (): void {
                Route::post('connections', [ActionsController::class, 'storeConnection'])->name('connections.store');
                Route::post('connections/{connection}', [ActionsController::class, 'updateConnection'])->whereNumber('connection')->name('connections.update');
                Route::post('connections/{connection}/test', [ActionsController::class, 'testConnection'])->whereNumber('connection')->name('connections.test');
                Route::post('connections/{connection}/resume', [ActionsController::class, 'resumeConnection'])->whereNumber('connection')->name('connections.resume');
                Route::post('connections/{connection}/sync', [ActionsController::class, 'sync'])->whereNumber('connection')->name('connections.sync');
                Route::post('connections/{connection}/confirm-all', [ActionsController::class, 'confirmAll'])->whereNumber('connection')->name('connections.confirm-all');
                Route::post('connections/{connection}/delete', [ActionsController::class, 'destroyConnection'])->whereNumber('connection')->name('connections.destroy');
                Route::post('mappings/{mapping}/confirm', [ActionsController::class, 'confirmMapping'])->whereNumber('mapping')->name('mappings.confirm');
                Route::post('mappings/{mapping}/link', [ActionsController::class, 'linkMapping'])->whereNumber('mapping')->name('mappings.link');
                Route::post('mappings/{mapping}/ignore', [ActionsController::class, 'ignoreMapping'])->whereNumber('mapping')->name('mappings.ignore');
                Route::post('mappings/{mapping}/reset', [ActionsController::class, 'resetMapping'])->whereNumber('mapping')->name('mappings.reset');
                Route::post('mappings/{mapping}/resync', [ActionsController::class, 'resyncMapping'])->whereNumber('mapping')->name('mappings.resync');
                Route::post('failed/{deadLetter}/retry', [ActionsController::class, 'retryDeadLetter'])->whereNumber('deadLetter')->name('failed.retry');
                Route::post('failed/{deadLetter}/dismiss', [ActionsController::class, 'dismissDeadLetter'])->whereNumber('deadLetter')->name('failed.dismiss');
            });

        Route::middleware(['throttle:600,1'])
            ->post('webhooks/plugins/'.self::SLUG.'/{connection}', WebhookController::class)
            ->whereNumber('connection')
            ->name(self::ROUTE.'webhooks.receive');
    }

    private function registerPages(): void
    {
        register_page(self::ROUTE.'overview', 'Plugin::'.self::SLUG.'/Overview', [
            'uri' => self::URI,
            'title' => 'Shopify',
            'permission' => Permissions::view(),
            'props' => fn ($request, $user) => PagesController::overview($request, $user),
        ]);

        register_page(self::ROUTE.'mappings', 'Plugin::'.self::SLUG.'/Mappings', [
            'uri' => self::URI.'/mappings',
            'title' => 'Shopify mappings',
            'permission' => Permissions::view(),
            'props' => fn ($request, $user) => PagesController::mappings($request, $user),
        ]);

        register_page(self::ROUTE.'activity', 'Plugin::'.self::SLUG.'/Activity', [
            'uri' => self::URI.'/activity',
            'title' => 'Shopify sync activity',
            'permission' => Permissions::view(),
            'props' => fn ($request, $user) => PagesController::activity($request, $user),
        ]);
    }

    private function registerNavigation(): void
    {
        register_menu_item([
            'label' => 'Shopify',
            'label_key' => 'plugins.'.self::SLUG.'.nav.title',
            'route' => self::ROUTE.'overview',
            'permission' => Permissions::view(),
            'position' => 270,
        ]);
    }

    /**
     * Core change hooks only queue work (Listeners); the kit's own hooks
     * chain follow-up work and outbound events.
     */
    private function registerHooks(): void
    {
        add_action('stock_changed', [Listeners::class, 'stockChanged']);
        add_action('product_updated', [Listeners::class, 'catalogChanged']);
        add_action('variant_updated', [Listeners::class, 'catalogChanged']);

        add_action(Kit::HOOK_PREFIX.'connection_verified', static function (Connection $connection, ?int $userId = null): void {
            RegisterWebhooks::dispatch($connection->id);
            RunSync::dispatch($connection->id, ['locations'], 'initial', 'user', $userId);
        });

        add_action(Kit::HOOK_PREFIX.'sync_failed', static function (Connection $connection, DeadLetter $letter): void {
            Events::syncFailed($connection, $letter);
        });
    }

    private function registerWebhookEvents(): void
    {
        Events::register();
    }

    private function registerMcpTools(): void
    {
        if (function_exists('register_mcp_tool')) {
            register_mcp_tool(self::SLUG, SyncStatusTool::class, (array) Permissions::view());
        }
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
        return [SyncCommand::class, PruneCommand::class];
    }

    private function registerSchedule(): void
    {
        $define = static function (Schedule $schedule): void {
            $schedule->command('shopify:sync')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('shopify:prune')->dailyAt('04:30')->withoutOverlapping();
        };

        if (app()->resolved(Schedule::class)) {
            $define(app(Schedule::class));

            return;
        }

        app()->afterResolving(Schedule::class, $define);
    }
}
