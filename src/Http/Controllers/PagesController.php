<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Http\Controllers;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inventoros\Plugins\Shopify\Jobs\RegisterWebhooks;
use Inventoros\Plugins\Shopify\Kit\Connections\ConnectionHealth;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use Inventoros\Plugins\Shopify\Kit\Models\SyncLog;
use Inventoros\Plugins\Shopify\Kit\Models\SyncRun;
use Inventoros\Plugins\Shopify\Shopify\ShopifyClient;
use Inventoros\Plugins\Shopify\Shopify\SyncPolicy;
use Inventoros\Plugins\Shopify\ShopifyPlugin;
use Inventoros\Plugins\Shopify\Support\Core;
use Inventoros\Plugins\Shopify\Support\Permissions;
use Inventoros\Plugins\Shopify\Sync\Catalog;
use Inventoros\Plugins\Shopify\Sync\Locations;
use Inventoros\Plugins\Shopify\Sync\OrderImporter;

/**
 * Props of the plugin's three pages. register_page() calls these only for
 * users who passed the page permission; every query is limited to the
 * viewer's organization, and no credential ever leaves the server.
 */
final class PagesController
{
    private const PER_PAGE = 50;

    /**
     * @return array<string, mixed>
     */
    public static function overview(Request $request, User $user): array
    {
        $organizationId = (int) $user->organization_id;
        $health = app(ConnectionHealth::class);

        $connections = Connection::query()
            ->forOrganization($organizationId)
            ->orderBy('name')
            ->get()
            ->map(fn (Connection $connection) => self::connection($connection) + ['health' => $health->summary($connection)])
            ->values()
            ->all();

        return [
            'connections' => $connections,
            'canManage' => Permissions::canManage($user),
            'apiVersion' => ShopifyClient::API_VERSION,
            'defaults' => SyncPolicy::DEFAULTS,
            'urls' => self::urls(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mappings(Request $request, User $user): array
    {
        $organizationId = (int) $user->organization_id;
        $connections = self::connectionList($organizationId);
        $connection = self::selected($request, $organizationId, $connections);

        $entity = in_array($request->query('entity'), [Catalog::ENTITY, Locations::ENTITY, OrderImporter::ENTITY], true)
            ? (string) $request->query('entity')
            : Catalog::ENTITY;
        $status = in_array($request->query('status'), Mapping::STATUSES, true) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));

        $rows = [];
        $total = 0;
        $counts = [];

        if ($connection !== null) {
            $base = Mapping::query()->forOrganization($organizationId)->where('connection_id', $connection->id)->where('entity', $entity);

            $counts = (clone $base)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
                ->map(fn ($count) => (int) $count)->all();

            $query = (clone $base)
                ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
                ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('sku', 'like', '%'.self::escapeLike($search).'%')
                    ->orWhere('external_label', 'like', '%'.self::escapeLike($search).'%')
                    ->orWhere('external_id', $search)));

            $total = (clone $query)->count();
            $mappings = $query->orderByRaw('case when status = ? then 0 when status = ? then 1 when status = ? then 2 else 3 end', [Mapping::CONFLICT, Mapping::SUGGESTED, Mapping::UNMATCHED])
                ->orderBy('id')
                ->forPage($page, self::PER_PAGE)
                ->get();

            $rows = self::mappingRows($organizationId, $mappings);
        }

        return [
            'connections' => $connections,
            'connection' => $connection === null ? null : self::connection($connection),
            'entity' => $entity,
            'status' => $status,
            'search' => $search,
            'rows' => $rows,
            'counts' => $counts,
            'pagination' => ['page' => $page, 'perPage' => self::PER_PAGE, 'total' => $total],
            'warehouses' => Core::warehouses($organizationId)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($w) => ['id' => (int) $w->id, 'name' => (string) $w->name])->all(),
            'canManage' => Permissions::canManage($user),
            'urls' => self::urls(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function activity(Request $request, User $user): array
    {
        $organizationId = (int) $user->organization_id;
        $connections = self::connectionList($organizationId);
        $connection = self::selected($request, $organizationId, $connections);
        $tab = in_array($request->query('tab'), ['runs', 'errors', 'failed'], true) ? (string) $request->query('tab') : 'runs';
        $page = max(1, (int) $request->query('page', 1));

        $runs = [];
        $logs = [];
        $deadLetters = [];
        $run = null;
        $total = 0;

        if ($connection !== null) {
            if ($tab === 'runs') {
                $query = SyncRun::query()->forOrganization($organizationId)->where('connection_id', $connection->id);
                $total = (clone $query)->count();
                $runs = $query->latest('id')->forPage($page, self::PER_PAGE)->get()->map(fn (SyncRun $r) => self::runRow($r))->all();

                $runId = (int) $request->query('run', 0);
                if ($runId > 0) {
                    $selected = SyncRun::query()->forOrganization($organizationId)->where('connection_id', $connection->id)->find($runId);
                    if ($selected !== null) {
                        $run = self::runRow($selected) + [
                            'logs' => $selected->logs()->orderBy('id')->limit(500)->get()->map(fn (SyncLog $l) => self::logRow($l))->all(),
                        ];
                    }
                }
            } elseif ($tab === 'errors') {
                $query = SyncLog::query()->forOrganization($organizationId)->where('connection_id', $connection->id)
                    ->whereIn('level', [SyncLog::WARNING, SyncLog::ERROR]);
                $total = (clone $query)->count();
                $logs = $query->latest('id')->forPage($page, self::PER_PAGE)->get()->map(fn (SyncLog $l) => self::logRow($l))->all();
            } else {
                $query = DeadLetter::query()->forOrganization($organizationId)->where('connection_id', $connection->id)->whereNull('resolved_at');
                $total = (clone $query)->count();
                $deadLetters = $query->latest('id')->forPage($page, self::PER_PAGE)->get()->map(fn (DeadLetter $d) => [
                    'id' => $d->id,
                    'kind' => $d->kind,
                    'reference' => $d->reference,
                    'error' => $d->error,
                    'attempts' => $d->attempts,
                    'failed_at' => $d->failed_at?->toIso8601String(),
                ])->all();
            }
        }

        return [
            'connections' => $connections,
            'connection' => $connection === null ? null : self::connection($connection),
            'health' => $connection === null ? null : app(ConnectionHealth::class)->summary($connection),
            'tab' => $tab,
            'runs' => $runs,
            'run' => $run,
            'logs' => $logs,
            'deadLetters' => $deadLetters,
            'pagination' => ['page' => $page, 'perPage' => self::PER_PAGE, 'total' => $total],
            'canManage' => Permissions::canManage($user),
            'urls' => self::urls(),
        ];
    }

    /**
     * What the browser may know about a connection: never the credentials,
     * only whether each one is set.
     *
     * @return array<string, mixed>
     */
    public static function connection(Connection $connection): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'shop_domain' => $connection->shop_domain,
            'status' => $connection->status,
            'settings' => SyncPolicy::for($connection)->all(),
            'shop_name' => ($connection->remote ?? [])['shop_name'] ?? null,
            'currency' => ($connection->remote ?? [])['currency'] ?? null,
            'webhooks_registered_at' => ($connection->remote ?? [])['webhooks_registered_at'] ?? null,
            'has_access_token' => $connection->hasCredential('access_token'),
            'has_client_id' => $connection->hasCredential('client_id'),
            'has_webhook_secret' => $connection->hasCredential('webhook_secret'),
            'last_tested_at' => $connection->last_tested_at?->toIso8601String(),
            'webhook_url' => RegisterWebhooks::callbackUrl($connection),
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function urls(): array
    {
        return [
            'overview' => route('plg.shopify.overview'),
            'mappings' => route('plg.shopify.mappings'),
            'activity' => route('plg.shopify.activity'),
            'connections' => route('plg.shopify.actions.connections.store'),
            'actions' => url(ShopifyPlugin::URI.'/actions'),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, shop_domain: string, status: string}>
     */
    private static function connectionList(int $organizationId): array
    {
        return Connection::query()->forOrganization($organizationId)->orderBy('name')->get()
            ->map(fn (Connection $c) => ['id' => $c->id, 'name' => $c->name, 'shop_domain' => $c->shop_domain, 'status' => $c->status])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $connections
     */
    private static function selected(Request $request, int $organizationId, array $connections): ?Connection
    {
        $id = (int) $request->query('connection', $connections[0]['id'] ?? 0);

        return $id > 0 ? Connection::query()->forOrganization($organizationId)->find($id) : null;
    }

    /**
     * @param  Collection<int, Mapping>  $mappings
     * @return array<int, array<string, mixed>>
     */
    private static function mappingRows(int $organizationId, Collection $mappings): array
    {
        $ids = fn (string $type) => $mappings->where('internal_type', $type)->pluck('internal_id')->filter()->unique()->values()->all();

        $products = Core::products($organizationId)->whereIn('id', $ids('product'))->get(['id', 'name', 'sku', 'stock'])->keyBy('id');
        $variants = Core::variants($organizationId)->whereIn('id', $ids('variant'))->with('product:id,name')->get()->keyBy('id');
        $warehouses = Core::warehouses($organizationId)->whereIn('id', $ids('warehouse'))->get(['id', 'name'])->keyBy('id');
        $orders = Core::orders($organizationId)->whereIn('id', $ids('order'))->get(['id', 'order_number', 'status'])->keyBy('id');

        return $mappings->map(function (Mapping $m) use ($products, $variants, $warehouses, $orders) {
            $internal = null;

            if ($m->internal_id !== null) {
                $internal = match ($m->internal_type) {
                    'product' => ($p = $products->get($m->internal_id)) instanceof Product
                        ? ['label' => $p->name, 'sku' => $p->sku, 'stock' => (int) $p->stock, 'url' => route('products.show', $p->id)] : null,
                    'variant' => ($v = $variants->get($m->internal_id)) instanceof ProductVariant
                        ? ['label' => trim(($v->product?->name ?? '').' / '.($v->title ?? ''), ' /'), 'sku' => $v->sku, 'stock' => (int) $v->stock, 'url' => route('products.show', $v->product_id)] : null,
                    'warehouse' => ($w = $warehouses->get($m->internal_id)) !== null ? ['label' => $w->name] : null,
                    Locations::ALL_STOCK => ['label' => 'all'],
                    'order' => ($o = $orders->get($m->internal_id)) !== null
                        ? ['label' => $o->order_number, 'status' => $o->status instanceof \BackedEnum ? $o->status->value : (string) $o->status, 'url' => route('orders.show', $o->id)] : null,
                    default => null,
                };
            }

            return [
                'id' => $m->id,
                'entity' => $m->entity,
                'external_id' => $m->external_id,
                'external_label' => $m->external_label,
                'sku' => $m->sku,
                'status' => $m->status,
                'internal_type' => $m->internal_type,
                'internal_id' => $m->internal_id,
                'internal' => $internal,
                'primary' => (bool) $m->meta('primary', false),
                'conflict' => $m->meta('conflict'),
                'price' => $m->meta('price'),
                'synced_at' => $m->synced_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function runRow(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'type' => $run->type,
            'resource' => $run->resource,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'processed' => $run->processed,
            'created' => $run->created,
            'updated' => $run->updated,
            'skipped' => $run->skipped,
            'failed' => $run->failed,
            'error_summary' => $run->error_summary,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function logRow(SyncLog $log): array
    {
        return [
            'id' => $log->id,
            'level' => $log->level,
            'entity' => $log->entity,
            'external_id' => $log->external_id,
            'internal_id' => $log->internal_id,
            'action' => $log->action,
            'message' => $log->message,
            'run_id' => $log->sync_run_id,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
