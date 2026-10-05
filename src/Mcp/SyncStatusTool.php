<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Inventoros\Plugins\Shopify\Kit\Connections\ConnectionHealth;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;

/**
 * shopify_sync_status: the health of the organization's Shopify
 * connections (state, last sync, queue depth, error rate, failed work).
 * Read only. Core lists and runs it only for users holding shopify.view
 * (or shopify.manage) whose token allows it.
 */
#[IsReadOnly]
class SyncStatusTool extends Tool
{
    protected string $name = 'shopify_sync_status';

    protected string $description = 'Health of the Shopify store connections: state, last sync, queue depth, error rate over 24 hours and failed work waiting for a retry.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->integer()->description('Only this connection (optional).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::guard('sanctum')->user() ?? request()?->user();

        if (! $user instanceof User || $user->organization_id === null) {
            return Response::error('Sign in with an Inventoros token.');
        }

        $health = app(ConnectionHealth::class);
        $only = $request->get('connection_id');

        $connections = Connection::query()
            ->forOrganization((int) $user->organization_id)
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('name')
            ->get();

        if ($only !== null && $connections->isEmpty()) {
            return Response::error('Connection not found in this organization.');
        }

        return Response::json([
            'connections' => $connections->map(fn (Connection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'shop_domain' => $connection->shop_domain,
                'status' => $connection->status,
            ] + $health->summary($connection))->values()->all(),
        ]);
    }
}
