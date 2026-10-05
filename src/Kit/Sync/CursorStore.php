<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Sync;

use Carbon\CarbonInterface;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Cursor;

/**
 * Incremental sync positions, one per connection and resource.
 *
 * Advance after each page is applied (not at the end of a run), so a run
 * that dies halfway resumes from its last good page. The position never
 * moves backwards.
 */
final class CursorStore
{
    public function get(Connection $connection, string $resource): ?Cursor
    {
        return Cursor::query()
            ->where('connection_id', $connection->id)
            ->where('resource', $resource)
            ->first();
    }

    public function position(Connection $connection, string $resource): ?CarbonInterface
    {
        return $this->get($connection, $resource)?->position_at;
    }

    public function advance(Connection $connection, string $resource, ?string $cursor, ?CarbonInterface $positionAt): Cursor
    {
        $row = $this->get($connection, $resource) ?? new Cursor([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'resource' => $resource,
        ]);

        $row->cursor = $cursor;

        if ($positionAt !== null && ($row->position_at === null || $positionAt->gt($row->position_at))) {
            $row->position_at = $positionAt;
        }

        $row->save();

        return $row;
    }

    public function reset(Connection $connection, string $resource): void
    {
        Cursor::query()
            ->where('connection_id', $connection->id)
            ->where('resource', $resource)
            ->delete();
    }
}
