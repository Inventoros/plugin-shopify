<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Sync\Payments;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class PaymentCompatibilityTest extends TestCase
{
    use ShopifyWorld;

    public function test_the_form_and_server_follow_the_core_payment_capability(): void
    {
        $this->setUpWorld();
        Queue::fake();
        $this->actingAs($this->admin)->get('/p/shopify')->assertOk()->assertInertia(fn ($page) => $page->where('supportsPaymentImport', Payments::supported()));
        $response = $this->post(route('plg.shopify.actions.connections.update', $this->store->id), [
            'name' => 'Payments', 'stock_source' => 'inventoros', 'product_source' => 'shopify',
            'import_orders' => true, 'import_payments' => true,
        ]);
        if (Payments::supported()) {
            $response->assertRedirect()->assertSessionHasNoErrors();
            $this->assertTrue($this->store->fresh()->setting('import_payments'));
        } else {
            $response->assertRedirect()->assertSessionHasErrors('import_payments');
            $this->assertFalse((bool) $this->store->fresh()->setting('import_payments'));
        }
    }
}
