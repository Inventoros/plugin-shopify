<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Inventoros\Plugins\Shopify\Jobs\PushProducts;
use Inventoros\Plugins\Shopify\Jobs\RunSync;
use Inventoros\Plugins\Shopify\Kit\Models\Cursor;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * Initial product sync proposes SKU matches for review, never guesses on
 * a conflict, and products/update applies (or not) by the conflict policy.
 */
final class CatalogMappingTest extends TestCase
{
    use ShopifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->mapLocations();
    }

    private function statusOf(string $variantId): string
    {
        return $this->mapping('variant', $variantId)->status;
    }

    public function test_the_initial_sync_suggests_sku_matches_and_leaves_the_rest_for_review(): void
    {
        RunSync::dispatch($this->store->id, ['products'], 'initial', 'user', $this->admin->id);

        $this->assertSame(2, $this->shopify->callsTo('products'), 'both pages read');
        $this->assertSame(Mapping::SUGGESTED, $this->statusOf('3001'));
        $this->assertSame($this->bolt->id, $this->mapping('variant', '3001')->internal_id);
        $this->assertSame('product', $this->mapping('variant', '3001')->internal_type);
        $this->assertSame(Mapping::SUGGESTED, $this->statusOf('3002'));
        $this->assertSame($this->teeS->id, $this->mapping('variant', '3002')->internal_id);
        $this->assertSame('variant', $this->mapping('variant', '3002')->internal_type);
        $this->assertSame(Mapping::UNMATCHED, $this->statusOf('3004'), 'no SKU');
        $this->assertSame(Mapping::UNMATCHED, $this->statusOf('3005'), 'no such SKU in Inventoros');
        $this->assertSame(Mapping::CONFLICT, $this->statusOf('3006'), 'BOLT-1 is already suggested for another Shopify variant');
        $this->assertNull($this->mapping('variant', '3006')->internal_id);
        $this->assertSame('4001', $this->mapping('variant', '3001')->meta('inventory_item_id'));
        $this->assertSame('cursor-page-2', Cursor::query()->where('connection_id', $this->store->id)->where('resource', 'products')->value('cursor'));
    }

    public function test_suggestions_do_not_sync_until_confirmed(): void
    {
        RunSync::dispatch($this->store->id, ['products'], 'initial', 'user', $this->admin->id);
        $this->shopify->calls = [];

        \App\Models\Inventory\StockAdjustment::adjust($this->bolt->fresh(), -1, 'manual', 'test', null, null, true, $this->admin, $this->binA->id);
        $this->assertSame(0, $this->shopify->callsTo('setInventoryQuantities'));

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.connections.confirm-all', $this->store))->assertRedirect();

        $this->assertSame(Mapping::MAPPED, $this->statusOf('3001'));
        $this->assertSame(Mapping::MAPPED, $this->statusOf('3003'));
        $this->assertSame(Mapping::CONFLICT, $this->statusOf('3006'), 'conflicts are not confirmed in bulk');
        $this->assertSame(3, $this->shopify->callsTo('setInventoryQuantities'), 'each confirmed item is pushed once');
    }

    public function test_two_inventoros_records_with_one_sku_are_a_conflict_not_a_guess(): void
    {
        $this->variant($this->tee, 'WASH-1', 'XL', 1);
        $this->product('WASH-1', 'Washer', 1);

        RunSync::dispatch($this->store->id, ['products'], 'initial', 'user', $this->admin->id);

        $this->assertSame(Mapping::CONFLICT, $this->statusOf('3005'));
        $this->assertStringContainsString('Several', (string) $this->mapping('variant', '3005')->meta('conflict'));
    }

    public function test_linking_to_a_record_another_variant_holds_is_refused(): void
    {
        RunSync::dispatch($this->store->id, ['products'], 'initial', 'user', $this->admin->id);

        $this->actingAs($this->admin)
            ->post(route('plg.shopify.actions.mappings.link', $this->mapping('variant', '3006')), ['sku' => 'BOLT-1'])
            ->assertSessionHasErrors('sku');

        $this->assertSame(Mapping::CONFLICT, $this->statusOf('3006'));
        $this->assertSame($this->bolt->id, $this->mapping('variant', '3001')->internal_id);
    }

    public function test_a_conflict_is_resolved_by_ignoring_one_side_and_linking_the_other(): void
    {
        RunSync::dispatch($this->store->id, ['products'], 'initial', 'user', $this->admin->id);
        $washer = $this->product('WASH-1', 'Washer', 2, $this->binA);

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.mappings.ignore', $this->mapping('variant', '3006')))->assertRedirect();
        $this->actingAs($this->admin)->post(route('plg.shopify.actions.mappings.link', $this->mapping('variant', '3005')), ['sku' => 'WASH-1'])->assertSessionHasNoErrors();

        $this->assertSame(Mapping::IGNORED, $this->statusOf('3006'));
        $this->assertSame(Mapping::MAPPED, $this->statusOf('3005'));
        $this->assertSame($washer->id, $this->mapping('variant', '3005')->internal_id);
    }

    public function test_reset_matches_the_sku_again(): void
    {
        RunSync::dispatch($this->store->id, ['products'], 'initial', 'user', $this->admin->id);

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.mappings.ignore', $this->mapping('variant', '3001')))->assertRedirect();
        $this->assertSame(Mapping::IGNORED, $this->statusOf('3001'));

        $this->actingAs($this->admin)->post(route('plg.shopify.actions.mappings.reset', $this->mapping('variant', '3001')))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(Mapping::SUGGESTED, $this->statusOf('3001'));
        $this->assertSame($this->bolt->id, $this->mapping('variant', '3001')->internal_id);
    }

    public function test_product_updates_change_inventoros_names_and_prices_when_shopify_owns_products(): void
    {
        $this->mapCatalog();

        $this->webhook('products/update', $this->payload('products_update'))->assertOk();

        $this->assertSame('Hex bolt M8', $this->bolt->fresh()->name);
        $this->assertSame('4.50', number_format((float) $this->bolt->fresh()->price, 2));
        $this->assertSame(Mapping::UNMATCHED, $this->statusOf('3007'), 'a new Shopify variant waits for review');
        $this->assertSame(0, $this->shopify->callsTo('updateProductTitle'), 'no echo back to Shopify');
    }

    public function test_an_older_product_update_is_ignored(): void
    {
        $this->mapCatalog();
        $this->webhook('products/update', $this->payload('products_update'))->assertOk();

        $older = $this->payload('products_update');
        $older['title'] = 'Old name';
        $older['updated_at'] = '2026-10-04T12:00:00Z';
        $this->webhook('products/update', $older)->assertOk();

        $this->assertSame('Hex bolt M8', $this->bolt->fresh()->name);
    }

    public function test_when_inventoros_owns_products_shopify_edits_are_not_applied_and_inventoros_edits_are_pushed(): void
    {
        $this->store->forceFill(['settings' => array_merge($this->store->settings, ['product_source' => 'inventoros'])])->save();
        $this->mapCatalog();

        $this->webhook('products/update', $this->payload('products_update'))->assertOk();
        $this->assertSame('Hex bolt', $this->bolt->fresh()->name);

        $bolt = $this->bolt->fresh();
        $bolt->name = 'Hex bolt (zinc)';
        $bolt->save();

        $titles = array_values(array_filter($this->shopify->calls, fn ($c) => $c[0] === 'updateProductTitle'));
        $this->assertSame([['2001', 'Hex bolt (zinc)']], array_column($titles, 1));
        $this->assertSame(1, $this->shopify->callsTo('updateVariantPrices'));
    }
}
