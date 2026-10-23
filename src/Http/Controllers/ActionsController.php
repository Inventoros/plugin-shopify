<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Inventoros\Plugins\Shopify\Jobs\ImportOrder;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Jobs\RunSync;
use Inventoros\Plugins\Shopify\Kit\Connections\ConnectionHealth;
use Inventoros\Plugins\Shopify\Kit\Jobs\DeadLetterQueue;
use Inventoros\Plugins\Shopify\Kit\Jobs\TestConnection;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingConflict;
use Inventoros\Plugins\Shopify\Kit\Mapping\MappingStore;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Outbox\Outbox;
use Inventoros\Plugins\Shopify\Shopify\ShopDomain;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\Support\Core;
use Inventoros\Plugins\Shopify\Support\Permissions;
use Inventoros\Plugins\Shopify\Sync\Catalog;
use Inventoros\Plugins\Shopify\Sync\FulfilmentPush;
use Inventoros\Plugins\Shopify\Sync\InventoryPush;
use Inventoros\Plugins\Shopify\Sync\Locations;
use Inventoros\Plugins\Shopify\Sync\OrderImporter;
use Inventoros\Plugins\Shopify\Sync\Payments;
use Inventoros\Plugins\Shopify\Sync\SkuMatcher;

/**
 * Every write the pages make, under /p/shopify/actions (web group: session,
 * CSRF, sign-in). Each one checks shopify.manage on the server and loads
 * records only from the user's organization (another organization's id is
 * a 404). Nothing here calls Shopify: tests and syncs are queued.
 */
final class ActionsController extends Controller
{
    public function storeConnection(Request $request): RedirectResponse
    {
        $user = $this->authorizeManage($request);

        $data = $this->validateConnection($request, null);
        $organizationId = (int) $user->organization_id;

        if (Connection::query()->forOrganization($organizationId)->where('shop_domain', $data['shop_domain'])->exists()) {
            throw ValidationException::withMessages(['shop_domain' => __('shopify::messages.domain_taken')]);
        }

        $connection = Connection::create([
            'organization_id' => $organizationId,
            'acting_user_id' => $user->id,
            'name' => $data['name'],
            'shop_domain' => $data['shop_domain'],
            'credentials' => array_filter(['access_token' => $data['access_token'] ?? null, 'webhook_secret' => $data['webhook_secret'], 'client_id' => $data['client_id'] ?? null]),
            'status' => Connection::PENDING,
            'settings' => SyncPolicy::sanitize($data + ['orders_since' => $data['orders_since'] ?? now()->toDateString()]),
        ]);

        TestConnection::dispatch($connection->id, $user->id);

        return back()->with('success', __('shopify::messages.connection_saved'));
    }

    public function updateConnection(Request $request, int $connection): RedirectResponse
    {
        $user = $this->authorizeManage($request);
        $record = $this->connection($request, $connection);
        $data = $this->validateConnection($request, $record);

        $credentials = $record->credentials ?? [];
        $retest = false;

        foreach (['access_token', 'webhook_secret', 'client_id'] as $key) {
            if (($data[$key] ?? '') !== '') {
                $credentials[$key] = $data[$key];
                $retest = true;
            }
        }

        if ($data['auth_mode'] !== $record->setting('auth_mode', 'token')) {
            $retest = true;
        }
        if ($data['auth_mode'] === 'client_credentials' && $retest) {
            unset($credentials['access_token'], $credentials['token_expires_at']);
        }
        if ($data['auth_mode'] === 'token') {
            unset($credentials['client_id'], $credentials['token_expires_at']);
        }

        if (($data['push_fulfilments'] ?? false) && ! $record->setting('push_fulfilments', false)) {
            $retest = true;
        }
        if (($data['import_payments'] ?? false) && ! $record->setting('import_payments', false)) {
            $retest = true; // Register the refund topic when payment imports are enabled.
        }

        $record->forceFill([
            'name' => $data['name'],
            'acting_user_id' => $user->id,
            'credentials' => $credentials,
            'settings' => SyncPolicy::sanitize($data, $record->settings ?? []),
        ]);

        if ($retest) {
            $record->status = Connection::PENDING;
        }

        $record->save();

        if ($retest) {
            TestConnection::dispatch($record->id, $user->id);
        }

        return back()->with('success', __('shopify::messages.connection_updated'));
    }

    public function testConnection(Request $request, int $connection): RedirectResponse
    {
        $user = $this->authorizeManage($request);
        $record = $this->connection($request, $connection);

        TestConnection::dispatch($record->id, $user->id);

        return back()->with('success', __('shopify::messages.test_queued'));
    }

    public function resumeConnection(Request $request, int $connection): RedirectResponse
    {
        $this->authorizeManage($request);
        app(ConnectionHealth::class)->resume($this->connection($request, $connection));

        return back()->with('success', __('shopify::messages.resumed'));
    }

    public function destroyConnection(Request $request, int $connection): RedirectResponse
    {
        $this->authorizeManage($request);

        // Mappings, history and queued work go with it (foreign keys); the
        // orders and stock changes it made are core records and stay.
        $this->connection($request, $connection)->delete();

        return redirect()->route('plg.shopify.overview')->with('success', __('shopify::messages.connection_removed'));
    }

    public function sync(Request $request, int $connection): RedirectResponse
    {
        $user = $this->authorizeManage($request);
        $record = $this->connection($request, $connection);

        $data = $request->validate([
            'resource' => ['required', Rule::in(['all', ...RunSync::RESOURCES])],
            'mode' => ['nullable', Rule::in(['initial', 'manual', 'incremental'])],
        ]);

        if (! $record->canSync()) {
            return back()->with('error', __('shopify::messages.cannot_sync'));
        }

        $resources = $data['resource'] === 'all' ? RunSync::RESOURCES : [$data['resource']];

        RunSync::dispatch($record->id, $resources, $data['mode'] ?? 'manual', 'user', $user->id);

        return back()->with('success', __('shopify::messages.sync_queued'));
    }

    public function confirmMapping(Request $request, int $mapping): RedirectResponse
    {
        $this->authorizeManage($request);
        $row = $this->mapping($request, $mapping);

        if ($row->status !== Mapping::SUGGESTED || $row->internal_id === null) {
            return back()->with('error', __('shopify::messages.nothing_to_confirm'));
        }

        app(MappingStore::class)->mark($row, Mapping::MAPPED);
        $this->afterLink($row);

        return back()->with('success', __('shopify::messages.mapping_confirmed'));
    }

    public function confirmAll(Request $request, int $connection): RedirectResponse
    {
        $this->authorizeManage($request);
        $record = $this->connection($request, $connection);

        $count = 0;
        Mapping::query()->forOrganization($record->organization_id)->where('connection_id', $record->id)
            ->where('entity', Catalog::ENTITY)->where('status', Mapping::SUGGESTED)->whereNotNull('internal_id')
            ->orderBy('id')
            ->each(function (Mapping $row) use (&$count) {
                app(MappingStore::class)->mark($row, Mapping::MAPPED);
                $this->afterLink($row);
                $count++;
            });

        return back()->with('success', __('shopify::messages.mappings_confirmed', ['count' => $count]));
    }

    /**
     * Link a Shopify variant to the Inventoros product or variant with the
     * given SKU, or a Shopify location to a warehouse (or all stock).
     */
    public function linkMapping(Request $request, int $mapping): RedirectResponse
    {
        $this->authorizeManage($request);
        $row = $this->mapping($request, $mapping);
        $organizationId = (int) $row->organization_id;

        try {
            if ($row->entity === Locations::ENTITY) {
                $data = $request->validate([
                    'warehouse_id' => ['required'],
                    'primary' => ['nullable', 'boolean'],
                ]);

                if ($data['warehouse_id'] === 'all') {
                    app(Locations::class)->map($row, Locations::ALL_STOCK, $organizationId, (bool) ($data['primary'] ?? false));
                } else {
                    $warehouse = Core::warehouses($organizationId)->find((int) $data['warehouse_id']);
                    if ($warehouse === null) {
                        throw ValidationException::withMessages(['warehouse_id' => __('shopify::messages.unknown_warehouse')]);
                    }
                    app(Locations::class)->map($row, Locations::WAREHOUSE, (int) $warehouse->id, (bool) ($data['primary'] ?? false));
                }

                $this->queueFullPush($row->connection_id);

                return back()->with('success', __('shopify::messages.location_mapped'));
            }

            if ($row->entity !== Catalog::ENTITY) {
                return back()->with('error', __('shopify::messages.cannot_link'));
            }

            $data = $request->validate(['sku' => ['required', 'string', 'max:191']]);
            $candidates = SkuMatcher::candidates($organizationId, $data['sku']);

            if (count($candidates) !== 1) {
                throw ValidationException::withMessages(['sku' => count($candidates) === 0
                    ? __('shopify::messages.sku_not_found')
                    : __('shopify::messages.sku_ambiguous')]);
            }

            app(MappingStore::class)->link($row, $candidates[0]['type'], $candidates[0]['id'], Mapping::MAPPED);
            $meta = $row->meta ?? [];
            unset($meta['conflict']);
            $row->forceFill(['meta' => $meta])->save();
            $this->afterLink($row);
        } catch (MappingConflict $conflict) {
            throw ValidationException::withMessages(['sku' => $conflict->getMessage()]);
        }

        return back()->with('success', __('shopify::messages.mapping_linked'));
    }

    public function ignoreMapping(Request $request, int $mapping): RedirectResponse
    {
        $this->authorizeManage($request);
        $row = $this->mapping($request, $mapping);

        if ($row->entity === OrderImporter::ENTITY && $row->internal_id !== null) {
            return back()->with('error', __('shopify::messages.cannot_ignore_imported'));
        }

        app(MappingStore::class)->unlink($row, Mapping::IGNORED);

        return back()->with('success', __('shopify::messages.mapping_ignored'));
    }

    /**
     * Drop the link and match again (by SKU for variants).
     */
    public function resetMapping(Request $request, int $mapping): RedirectResponse
    {
        $this->authorizeManage($request);
        $row = $this->mapping($request, $mapping);

        if ($row->entity === OrderImporter::ENTITY) {
            return back()->with('error', __('shopify::messages.cannot_reset_order'));
        }

        app(MappingStore::class)->unlink($row, $row->entity === Locations::ENTITY ? Mapping::PENDING : Mapping::UNMATCHED);

        if ($row->entity === Catalog::ENTITY) {
            app(Catalog::class)->match(Connection::query()->forOrganization((int) $row->organization_id)->findOrFail($row->connection_id), $row->refresh());
        }

        return back()->with('success', __('shopify::messages.mapping_reset'));
    }

    /**
     * Manual resync of one record: push a variant's stock, or re-import an
     * order.
     */
    public function resyncMapping(Request $request, int $mapping): RedirectResponse
    {
        $this->authorizeManage($request);
        $row = $this->mapping($request, $mapping);
        $connection = Connection::query()->findOrFail($row->connection_id);

        if (! $connection->canSync()) {
            return back()->with('error', __('shopify::messages.cannot_sync'));
        }

        if ($row->entity === OrderImporter::ENTITY) {
            ImportOrder::dispatch($connection->id, $row->external_id, $row->external_label);

            return back()->with('success', __('shopify::messages.resync_queued'));
        }

        if ($row->entity === Catalog::ENTITY && $row->isMapped() && SyncPolicy::for($connection)->pushesStock()) {
            app(Outbox::class)->put($connection, InventoryPush::KIND, InventoryPush::subject((string) $row->internal_type, (int) $row->internal_id));
            PushInventory::dispatch($connection->id);

            return back()->with('success', __('shopify::messages.resync_queued'));
        }

        if ($row->entity === Catalog::ENTITY && $row->isMapped()) {
            RunSync::dispatch($connection->id, ['inventory'], 'manual', 'user', $request->user()->id);

            return back()->with('success', __('shopify::messages.resync_queued'));
        }

        return back()->with('error', __('shopify::messages.cannot_resync'));
    }

    public function retryDeadLetter(Request $request, int $deadLetter): RedirectResponse
    {
        $user = $this->authorizeManage($request);
        $letter = $this->deadLetter($request, $deadLetter);

        try {
            app(DeadLetterQueue::class)->retry($letter, $user->id);
        } catch (InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', __('shopify::messages.retry_queued'));
    }

    public function dismissDeadLetter(Request $request, int $deadLetter): RedirectResponse
    {
        $user = $this->authorizeManage($request);
        app(DeadLetterQueue::class)->dismiss($this->deadLetter($request, $deadLetter), $user->id);

        return back()->with('success', __('shopify::messages.dismissed'));
    }

    private function afterLink(Mapping $row): void
    {
        if ($row->entity !== Catalog::ENTITY || ! $row->isMapped()) {
            return;
        }

        $connection = Connection::query()->find($row->connection_id);

        if ($connection !== null && $connection->status === Connection::CONNECTED && SyncPolicy::for($connection)->pushesStock()) {
            app(Outbox::class)->put($connection, InventoryPush::KIND, InventoryPush::subject((string) $row->internal_type, (int) $row->internal_id), [], SyncPolicy::for($connection)->pushDelay());
            PushInventory::dispatch($connection->id)->delay(now()->addSeconds(SyncPolicy::for($connection)->pushDelay()));
        }
    }

    private function queueFullPush(int $connectionId): void
    {
        $connection = Connection::query()->find($connectionId);

        if ($connection !== null && $connection->status === Connection::CONNECTED && SyncPolicy::for($connection)->pushesStock()
            && app(InventoryPush::class)->queueAll($connection) > 0) {
            PushInventory::dispatch($connection->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateConnection(Request $request, ?Connection $existing): array
    {
        // The shop of an existing connection never changes: a different
        // store is a new connection.
        if ($existing === null) {
            $request->merge(['shop_domain' => ShopDomain::normalize((string) $request->input('shop_domain', ''))]);
        }

        $request->merge(['auth_mode' => $request->input('auth_mode', $existing?->setting('auth_mode', 'token') ?? 'token')]);
        $clientMode = $request->input('auth_mode') === 'client_credentials';
        $secret = $existing === null ? 'required' : 'nullable';
        $tokenRequired = ! $clientMode && ($existing === null || $existing->setting('auth_mode', 'token') !== 'token');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'shop_domain' => $existing === null ? ['required', 'string', 'max:191'] : ['exclude'],
            'auth_mode' => ['required', Rule::in(['token', 'client_credentials'])],
            'client_id' => [$clientMode && ! $existing?->hasCredential('client_id') ? 'required' : 'nullable', 'string', 'max:255'],
            'access_token' => [$tokenRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'webhook_secret' => [$secret, 'string', 'max:255'],
            'stock_source' => ['required', Rule::in(SyncPolicy::STOCK_SOURCES)],
            'product_source' => ['required', Rule::in(SyncPolicy::PRODUCT_SOURCES)],
            'import_orders' => ['required', 'boolean'],
            'push_fulfilments' => ['nullable', 'boolean'],
            'import_payments' => ['nullable', 'boolean'],

            'import_test_orders' => ['nullable', 'boolean'],
            'orders_since' => ['required_with:history_before', 'nullable', 'date'],
            'history_before' => ['nullable', 'date', 'after_or_equal:orders_since', 'before_or_equal:today'],
        ]);

        if (($data['push_fulfilments'] ?? false) && ! FulfilmentPush::supported()) {
            throw ValidationException::withMessages(['push_fulfilments' => 'Update Inventoros to a version with shipment notification ownership before enabling this option.']);
        }

        if (($data['import_payments'] ?? false) && ! Payments::supported()) {
            throw ValidationException::withMessages(['import_payments' => 'Update Inventoros core to enable imported-payment records.']);
        }

        if ($existing === null && ! ShopDomain::valid($data['shop_domain'])) {
            throw ValidationException::withMessages(['shop_domain' => __('shopify::messages.invalid_domain')]);
        }

        return $data;
    }

    private function authorizeManage(Request $request): User
    {
        $user = $request->user();

        abort_unless(Permissions::canManage($user), 403);

        return $user;
    }

    private function connection(Request $request, int $id): Connection
    {
        return Connection::query()->forOrganization((int) $request->user()->organization_id)->findOrFail($id);
    }

    private function mapping(Request $request, int $id): Mapping
    {
        return Mapping::query()->forOrganization((int) $request->user()->organization_id)->findOrFail($id);
    }

    private function deadLetter(Request $request, int $id): DeadLetter
    {
        return DeadLetter::query()->forOrganization((int) $request->user()->organization_id)->whereNull('resolved_at')->findOrFail($id);
    }
}
