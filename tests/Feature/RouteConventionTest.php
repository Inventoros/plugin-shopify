<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\Shopify\ShopifyPlugin;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * Every route the plugin adds lives in its own namespace, which core never
 * uses: names plg.{slug}.*, URIs under /p/{slug}/, /api/v1/plugins/{slug}/
 * or /webhooks/plugins/{slug}/. Core refuses to activate a plugin whose
 * routes collide with the application's, so this is what keeps it
 * installable next to any core version and any other plugin.
 */
final class RouteConventionTest extends TestCase
{
    /**
     * @return array<string, RoutingRoute> name => route
     */
    private function pluginRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            $controller = (string) ($route->getAction('controller') ?? '');

            if (str_starts_with($name, 'plg.'.ShopifyPlugin::SLUG.'.')
                || str_starts_with($controller, 'Inventoros\\Plugins\\Shopify\\')) {
                $routes[$name] = $route;
            }
        }

        ksort($routes);

        return $routes;
    }

    public function test_the_plugin_registers_exactly_these_routes(): void
    {
        $uris = array_map(
            fn (RoutingRoute $route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri(),
            $this->pluginRoutes(),
        );

        $this->assertSame([
        'plg.shopify.actions.connections.confirm-all' => 'POST p/shopify/actions/connections/{connection}/confirm-all',
        'plg.shopify.actions.connections.destroy' => 'POST p/shopify/actions/connections/{connection}/delete',
        'plg.shopify.actions.connections.resume' => 'POST p/shopify/actions/connections/{connection}/resume',
        'plg.shopify.actions.connections.store' => 'POST p/shopify/actions/connections',
        'plg.shopify.actions.connections.sync' => 'POST p/shopify/actions/connections/{connection}/sync',
        'plg.shopify.actions.connections.test' => 'POST p/shopify/actions/connections/{connection}/test',
        'plg.shopify.actions.connections.update' => 'POST p/shopify/actions/connections/{connection}',
        'plg.shopify.actions.failed.dismiss' => 'POST p/shopify/actions/failed/{deadLetter}/dismiss',
        'plg.shopify.actions.failed.retry' => 'POST p/shopify/actions/failed/{deadLetter}/retry',
        'plg.shopify.actions.mappings.confirm' => 'POST p/shopify/actions/mappings/{mapping}/confirm',
        'plg.shopify.actions.mappings.ignore' => 'POST p/shopify/actions/mappings/{mapping}/ignore',
        'plg.shopify.actions.mappings.link' => 'POST p/shopify/actions/mappings/{mapping}/link',
        'plg.shopify.actions.mappings.reset' => 'POST p/shopify/actions/mappings/{mapping}/reset',
        'plg.shopify.actions.mappings.resync' => 'POST p/shopify/actions/mappings/{mapping}/resync',
        'plg.shopify.activity' => 'GET p/shopify/activity',
        'plg.shopify.mappings' => 'GET p/shopify/mappings',
        'plg.shopify.overview' => 'GET p/shopify',
        'plg.shopify.webhooks.receive' => 'POST webhooks/plugins/shopify/{connection}',
        ], $uris);
    }

    public function test_every_plugin_route_is_named_and_placed_in_the_plugin_namespace(): void
    {
        $slug = ShopifyPlugin::SLUG;
        $namespaces = ["p/{$slug}/", "api/v1/plugins/{$slug}/", "webhooks/plugins/{$slug}/"];

        $this->assertNotEmpty($this->pluginRoutes());

        foreach ($this->pluginRoutes() as $name => $route) {
            $this->assertStringStartsWith("plg.{$slug}.", $name, 'route names are plg.{slug}.*');

            $uri = trim($route->uri(), '/').'/';
            $this->assertTrue(
                collect($namespaces)->contains(fn (string $prefix) => str_starts_with($uri, $prefix)),
                "/{$route->uri()} must sit under /p/{$slug}/, /api/v1/plugins/{$slug}/ or /webhooks/plugins/{$slug}/",
            );
        }
    }

    public function test_nothing_is_left_at_the_old_unprefixed_uris(): void
    {
        $this->actingAs($this->admin)->get('/'.ShopifyPlugin::SLUG)->assertNotFound();

        foreach (array_keys($this->pluginRoutes()) as $name) {
            $this->assertFalse(Route::has(substr($name, strlen('plg.'))), "the old route name {$name} without plg. is gone");
        }
    }
}
