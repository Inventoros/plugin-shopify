<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use Inventoros\Plugins\Shopify\Jobs\RunSync;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\SyncLog;
use Inventoros\Plugins\Shopify\Support\Core;
use Inventoros\Plugins\Shopify\Tests\Concerns\ShopifyWorld;
use Inventoros\Plugins\Shopify\Tests\TestCase;

/**
 * A connection's acting user may work in the organization through a
 * membership (Multi-Company, 3PL operator staff) rather than as their home
 * organization: background work acts as them, working in the connection's
 * organization. Someone who is not a member at all still cannot be used.
 */
final class OrganizationMembershipTest extends TestCase
{
    use ShopifyWorld;

    private const MEMBERSHIPS = 'App\\Services\\Organizations\\OrganizationMembershipService';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(self::MEMBERSHIPS)) {
            $this->markTestSkipped('This core has no organization memberships.');
        }

        $this->setUpWorld();
        $this->store->forceFill(['settings' => array_merge($this->store->settings, ['stock_source' => 'shopify'])])->save();
        $this->mapLocations();
        $this->mapCatalog();
    }

    private function outsider(): User
    {
        return $this->makeUser($this->makeOrganization('Elsewhere'), 'admin');
    }

    public function test_a_member_from_another_organization_is_the_actor_of_an_inventory_pull(): void
    {
        $member = $this->outsider();
        app(self::MEMBERSHIPS)->add($this->organization->fresh(), $member, 'admin');
        $this->store->forceFill(['acting_user_id' => $member->id])->save();

        $actor = Core::actor($this->store->fresh());
        $this->assertSame($member->id, $actor->id);
        $this->assertSame($this->organization->id, (int) $actor->organization_id, 'working in the connection\'s organization');

        RunSync::dispatch($this->store->id, ['inventory'], 'manual', 'user', $member->id);

        $this->assertSame(28, $this->bolt->fresh()->stock);
        $adjustment = StockAdjustment::query()->where('product_id', $this->bolt->id)->latest('id')->first();
        $this->assertSame($member->id, $adjustment->user_id);
        $this->assertSame($this->organization->id, (int) $adjustment->organization_id);
        $this->assertSame(0, DeadLetter::query()->count());
    }

    public function test_a_member_from_another_organization_imports_orders_into_the_connection_organization(): void
    {
        $member = $this->outsider();
        app(self::MEMBERSHIPS)->add($this->organization->fresh(), $member, 'admin');
        $this->store->forceFill(['acting_user_id' => $member->id])->save();

        $this->webhook('orders/create', $this->payload('orders_create'))->assertOk();

        $order = Order::withoutGlobalScopes()->where('external_id', '5001')->sole();
        $this->assertSame($this->organization->id, (int) $order->organization_id);
        $this->assertSame($member->id, (int) $order->created_by);
        $this->assertSame(0, DeadLetter::query()->count());
    }

    public function test_a_user_of_another_organization_without_a_membership_is_still_refused(): void
    {
        $stranger = $this->outsider();
        $this->store->forceFill(['acting_user_id' => $stranger->id])->save();

        RunSync::dispatch($this->store->id, ['inventory'], 'manual', 'user', $this->admin->id);

        $this->assertSame(10, $this->bolt->fresh()->stock);
        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertTrue(SyncLog::query()->where('message', 'like', '%no user to act as%')->exists());

        $this->expectException(PermanentError::class);
        Core::actor($this->store->fresh());
    }
}
