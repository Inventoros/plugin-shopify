<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Concerns;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Kit\Webhooks\HmacSignature;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Sync\Catalog;
use Inventoros\Plugins\Shopify\Sync\Locations;

/**
 * A small store for the sync tests, matching the recorded Shopify
 * fixtures: two warehouses with a bin each, a simple product BOLT-1 (10 in
 * bin A-01) and a T-shirt sold by variant (TEE-S 5, TEE-M 4). WASH-1 is
 * deliberately missing from Inventoros.
 */
trait ShopifyWorld
{
    protected Warehouse $main;

    protected Warehouse $annex;

    protected ProductLocation $binA;

    protected ProductLocation $annexBin;

    protected Product $bolt;

    protected Product $tee;

    protected ProductVariant $teeS;

    protected ProductVariant $teeM;

    protected Connection $store;

    protected const SECRET = 'whsec_test_secret_value';

    protected const TOKEN = 'shpat_test_token_value_1234567890';

    protected function setUpWorld(): void
    {
        Mail::fake();
        Notification::fake();

        $this->main = $this->warehouse('Main');
        $this->annex = $this->warehouse('Annex');
        $this->binA = $this->bin('A-01', $this->main);
        $this->annexBin = $this->bin('X-01', $this->annex);

        $this->bolt = $this->product('BOLT-1', 'Hex bolt', 10, $this->binA);

        $this->tee = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEE',
            'name' => 'T-shirt',
            'price' => 15,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
            'has_variants' => true,
        ]);
        $this->teeS = $this->variant($this->tee, 'TEE-S', 'S', 5);
        $this->teeM = $this->variant($this->tee, 'TEE-M', 'M', 4);

        $this->store = $this->connect();
    }

    protected function warehouse(string $name, ?Organization $organization = null): Warehouse
    {
        return Warehouse::create([
            'organization_id' => ($organization ?? $this->organization)->id,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).'-'.bin2hex(random_bytes(2)),
            'is_active' => true,
        ]);
    }

    protected function bin(string $code, Warehouse $warehouse): ProductLocation
    {
        return ProductLocation::create([
            'organization_id' => $warehouse->organization_id,
            'name' => 'Bin '.$code,
            'code' => $code.'-'.bin2hex(random_bytes(2)),
            'warehouse_id' => $warehouse->id,
            'is_active' => true,
        ]);
    }

    protected function product(string $sku, string $name, int $stock, ?ProductLocation $bin = null, ?Organization $organization = null): Product
    {
        $product = Product::create([
            'organization_id' => ($organization ?? $this->organization)->id,
            'sku' => $sku,
            'name' => $name,
            'price' => 4,
            'purchase_price' => 2,
            'currency' => 'USD',
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
            'location_id' => $bin?->id,
        ]);

        if ($bin !== null && $stock > 0) {
            ProductLocationStock::create([
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'location_id' => $bin->id,
                'quantity' => $stock,
            ]);
        }

        return $product;
    }

    protected function variant(Product $product, string $sku, string $size, int $stock): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'organization_id' => $product->organization_id,
            'sku' => $sku,
            'title' => $size,
            'option_values' => ['Size' => $size],
            'price' => 15,
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * A tested, connected store (no Shopify call: as if TestConnection passed).
     *
     * @param  array<string, mixed>  $settings
     */
    protected function connect(array $settings = [], ?Organization $organization = null, ?User $actor = null, string $domain = 'acme-store.myshopify.com'): Connection
    {
        $organization ??= $this->organization;

        return Connection::create([
            'organization_id' => $organization->id,
            'acting_user_id' => ($actor ?? $this->admin)->id,
            'name' => 'Acme Store',
            'shop_domain' => $domain,
            'credentials' => ['access_token' => self::TOKEN, 'webhook_secret' => self::SECRET],
            'status' => Connection::CONNECTED,
            'remote' => ['shop_name' => 'Acme Store', 'currency' => 'USD'],
            'settings' => array_merge(SyncPolicy::sanitize($settings + ['orders_since' => '2026-01-01']), ['push_delay_seconds' => $settings['push_delay_seconds'] ?? 0]),
            'last_success_at' => now(),
        ]);
    }

    /**
     * Shopify location 1001 -> Main (primary), 1002 -> Annex.
     */
    protected function mapLocations(?Connection $connection = null): void
    {
        $connection ??= $this->store;
        $store = app(MappingStore::class);
        $locations = app(Locations::class);

        $main = $store->remember($connection, Locations::ENTITY, '1001', ['external_label' => 'Main warehouse']);
        $pop = $store->remember($connection, Locations::ENTITY, '1002', ['external_label' => 'Pop-up shop']);

        $locations->map($main, Locations::WAREHOUSE, $this->warehouseFor($connection, 'Main'), true);
        $locations->map($pop, Locations::WAREHOUSE, $this->warehouseFor($connection, 'Annex'), false);
    }

    private function warehouseFor(Connection $connection, string $name): int
    {
        return (int) Warehouse::query()->where('organization_id', $connection->organization_id)->where('name', $name)->value('id');
    }

    /**
     * Ingest the recorded catalog and confirm every SKU suggestion
     * (BOLT-1, TEE-S, TEE-M).
     */
    protected function mapCatalog(?Connection $connection = null): void
    {
        $connection ??= $this->store;
        $run = app(SyncRecorder::class)->start($connection, 'initial', 'products', 'user');

        foreach ($this->shopify->productPages as $page) {
            foreach ($page as $product) {
                app(Catalog::class)->ingest($connection, $product, $run, applyChanges: false);
            }
        }

        Mapping::query()->where('connection_id', $connection->id)->where('status', Mapping::SUGGESTED)->update(['status' => Mapping::MAPPED]);
    }

    protected function mapping(string $entity, string $externalId, ?Connection $connection = null): ?Mapping
    {
        return Mapping::query()
            ->where('connection_id', ($connection ?? $this->store)->id)
            ->where('entity', $entity)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(string $name): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/webhooks/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Deliver a signed webhook the way Shopify does.
     *
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $headers
     */
    protected function webhook(string $topic, array|string $payload, array $headers = [], ?Connection $connection = null, ?string $secret = null): TestResponse
    {
        $connection ??= $this->store;
        $body = is_string($payload) ? $payload : (string) json_encode($payload);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $connection->shop_domain,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => HmacSignature::sign($body, $secret ?? self::SECRET),
            'HTTP_X_SHOPIFY_EVENT_ID' => 'evt-'.bin2hex(random_bytes(6)),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-'.bin2hex(random_bytes(6)),
            'HTTP_X_SHOPIFY_TRIGGERED_AT' => now()->toIso8601String(),
            'HTTP_X_SHOPIFY_API_VERSION' => '2026-07',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/webhooks/plugins/shopify/'.$connection->id, [], [], [], $server, $body);
    }

    /**
     * A member holding exactly these permissions (through a custom role).
     *
     * @param  array<int, string>  $permissions
     */
    protected function worker(array $permissions, ?Organization $organization = null): User
    {
        $organization ??= $this->organization;
        $user = $this->makeUser($organization, 'member');
        $role = Role::create([
            'slug' => 'worker-'.bin2hex(random_bytes(3)),
            'name' => 'Worker',
            'is_system' => false,
            'organization_id' => $organization->id,
            'permissions' => $permissions,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }
}
