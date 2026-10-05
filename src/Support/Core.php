<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Support;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;

/**
 * Queries on core records for one organization, explicitly.
 *
 * Core's OrganizationScope filters by the signed-in user, and the plugin's
 * jobs and webhooks run without one (or, in a request, possibly as a user
 * of another organization). Every core read here names the connection's
 * organization instead, so the scope's answer never matters.
 */
final class Core
{
    /** @return Builder<Product> */
    public static function products(int $organizationId): Builder
    {
        return Product::withoutGlobalScope(OrganizationScope::class)->where('products.organization_id', $organizationId);
    }

    /** @return Builder<ProductVariant> */
    public static function variants(int $organizationId): Builder
    {
        return ProductVariant::withoutGlobalScope(OrganizationScope::class)->where('product_variants.organization_id', $organizationId);
    }

    /** @return Builder<Order> */
    public static function orders(int $organizationId): Builder
    {
        return Order::withoutGlobalScope(OrganizationScope::class)->where('orders.organization_id', $organizationId);
    }

    /** @return Builder<Warehouse> */
    public static function warehouses(int $organizationId): Builder
    {
        return Warehouse::withoutGlobalScope(OrganizationScope::class)->where('warehouses.organization_id', $organizationId);
    }

    /** @return Builder<ProductLocation> */
    public static function locations(int $organizationId): Builder
    {
        return ProductLocation::withoutGlobalScope(OrganizationScope::class)->where('product_locations.organization_id', $organizationId);
    }

    /**
     * Run $work signed in as $user, then restore whoever was signed in.
     *
     * Some core services take the actor from auth() rather than a
     * parameter (OrderService::cancel() writes its restock ledger rows with
     * auth()->id()). Jobs have no signed-in user, so they would fail; this
     * signs in the connection's user for that one call only.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function as(User $user, callable $work): mixed
    {
        $guard = auth()->guard();
        $previous = $guard->user();

        $guard->setUser($user);

        try {
            return $work();
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /**
     * The user background work acts as (core records name an actor): the
     * person who connected the store, while they still belong to the
     * organization.
     */
    public static function actor(Connection $connection): User
    {
        $user = $connection->acting_user_id === null ? null : User::query()->find($connection->acting_user_id);

        if ($user === null || (int) $user->organization_id !== (int) $connection->organization_id) {
            throw new PermanentError('This connection has no user to act as. Open the connection settings and save them to act as yourself.');
        }

        return $user;
    }
}
