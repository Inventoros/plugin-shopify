<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;

/**
 * A connection's sync settings and conflict policy.
 *
 * stock_source decides who owns stock levels:
 *   inventoros  Inventoros pushes its quantities to Shopify; Shopify's
 *               inventory_levels/update webhooks are recorded, not applied.
 *   shopify     Shopify's levels are applied to Inventoros (as audited
 *               adjustments); Inventoros never pushes stock.
 *
 * product_source decides who owns titles and prices of mapped products:
 *   shopify     products/update changes Inventoros names and prices.
 *   inventoros  Inventoros edits are pushed to Shopify; Shopify edits only
 *               refresh the mapping.
 *
 * Orders always flow from Shopify into Inventoros.
 */
final class SyncPolicy
{
    public const STOCK_SOURCES = ['inventoros', 'shopify'];

    public const PRODUCT_SOURCES = ['shopify', 'inventoros'];

    public const DEFAULTS = [
        'stock_source' => 'inventoros',
        'product_source' => 'shopify',
        'import_orders' => true,
        'import_test_orders' => false,
        'orders_since' => null,
        'push_delay_seconds' => 10,
    ];

    private function __construct(private readonly array $settings) {}

    public static function for(Connection $connection): self
    {
        return new self(array_merge(self::DEFAULTS, array_intersect_key($connection->settings ?? [], self::DEFAULTS)));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> settings to store
     */
    public static function sanitize(array $input, array $current = []): array
    {
        $settings = array_merge(self::DEFAULTS, array_intersect_key($current, self::DEFAULTS));

        if (in_array($input['stock_source'] ?? null, self::STOCK_SOURCES, true)) {
            $settings['stock_source'] = $input['stock_source'];
        }

        if (in_array($input['product_source'] ?? null, self::PRODUCT_SOURCES, true)) {
            $settings['product_source'] = $input['product_source'];
        }

        foreach (['import_orders', 'import_test_orders'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $settings[$flag] = (bool) $input[$flag];
            }
        }

        if (array_key_exists('orders_since', $input)) {
            $settings['orders_since'] = $input['orders_since'] ?: null;
        }

        return $settings;
    }

    public function pushesStock(): bool
    {
        return $this->settings['stock_source'] === 'inventoros';
    }

    public function pullsStock(): bool
    {
        return $this->settings['stock_source'] === 'shopify';
    }

    public function pushesProducts(): bool
    {
        return $this->settings['product_source'] === 'inventoros';
    }

    public function pullsProducts(): bool
    {
        return $this->settings['product_source'] === 'shopify';
    }

    public function importsOrders(): bool
    {
        return (bool) $this->settings['import_orders'];
    }

    public function importsTestOrders(): bool
    {
        return (bool) $this->settings['import_test_orders'];
    }

    public function ordersSince(): ?string
    {
        return $this->settings['orders_since'];
    }

    public function pushDelay(): int
    {
        return max(0, (int) $this->settings['push_delay_seconds']);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->settings;
    }
}
