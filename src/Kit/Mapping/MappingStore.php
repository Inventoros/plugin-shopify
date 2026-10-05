<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Mapping;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\Mapping;

/**
 * External id to core id mappings, unique both ways per connection and
 * entity.
 *
 * remember() records what the provider told us about an external record
 * without touching its link; link() and unlink() change the link and refuse
 * a core record that is already taken. isStale() and touch() keep
 * out-of-order deliveries from undoing newer state.
 */
final class MappingStore
{
    public function find(Connection $connection, string $entity, string|int $externalId): ?Mapping
    {
        return Mapping::query()
            ->where('connection_id', $connection->id)
            ->where('entity', $entity)
            ->where('external_id', (string) $externalId)
            ->first();
    }

    /**
     * The confirmed mapping of a core record, if any.
     */
    public function forInternal(Connection $connection, string $entity, string $internalType, int $internalId): ?Mapping
    {
        return Mapping::query()
            ->where('connection_id', $connection->id)
            ->where('entity', $entity)
            ->where('internal_type', $internalType)
            ->where('internal_id', $internalId)
            ->where('status', Mapping::MAPPED)
            ->first();
    }

    /**
     * @return Collection<int, Mapping>
     */
    public function mapped(Connection $connection, string $entity): Collection
    {
        return Mapping::query()
            ->where('connection_id', $connection->id)
            ->where('entity', $entity)
            ->where('status', Mapping::MAPPED)
            ->whereNotNull('internal_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Create or refresh the external side of a mapping. $attributes may
     * hold external_label, external_parent_id, sku and meta (merged); the
     * link and status are only set on a new row (status defaults to
     * pending).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function remember(Connection $connection, string $entity, string|int $externalId, array $attributes = []): Mapping
    {
        $mapping = $this->find($connection, $entity, $externalId);

        if ($mapping === null) {
            try {
                return DB::transaction(fn () => Mapping::create([
                    'organization_id' => $connection->organization_id,
                    'connection_id' => $connection->id,
                    'entity' => $entity,
                    'external_id' => (string) $externalId,
                    'external_parent_id' => $attributes['external_parent_id'] ?? null,
                    'external_label' => isset($attributes['external_label']) ? mb_substr((string) $attributes['external_label'], 0, 255) : null,
                    'sku' => $attributes['sku'] ?? null,
                    'status' => $attributes['status'] ?? Mapping::PENDING,
                    'meta' => $attributes['meta'] ?? null,
                ]));
            } catch (UniqueConstraintViolationException) {
                $mapping = $this->find($connection, $entity, $externalId);

                if ($mapping === null) {
                    throw new \RuntimeException("Mapping {$entity} {$externalId} collided and cannot be found.");
                }
            }
        }

        foreach (['external_parent_id', 'sku'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $mapping->{$key} = $attributes[$key];
            }
        }

        if (array_key_exists('external_label', $attributes)) {
            $mapping->external_label = $attributes['external_label'] === null ? null : mb_substr((string) $attributes['external_label'], 0, 255);
        }

        if (isset($attributes['meta']) && is_array($attributes['meta'])) {
            $mapping->meta = array_merge($mapping->meta ?? [], $attributes['meta']);
        }

        if ($mapping->isDirty()) {
            $mapping->save();
        }

        return $mapping;
    }

    /**
     * Point a mapping at a core record.
     *
     * @throws MappingConflict when another external record of this connection already holds it
     */
    public function link(Mapping $mapping, string $internalType, int $internalId, string $status = Mapping::MAPPED): Mapping
    {
        $taken = Mapping::query()
            ->where('connection_id', $mapping->connection_id)
            ->where('entity', $mapping->entity)
            ->where('internal_type', $internalType)
            ->where('internal_id', $internalId)
            ->whereKeyNot($mapping->id)
            ->first();

        if ($taken !== null) {
            throw new MappingConflict($taken);
        }

        $mapping->forceFill([
            'internal_type' => $internalType,
            'internal_id' => $internalId,
            'status' => $status,
        ]);

        try {
            DB::transaction(fn () => $mapping->save());
        } catch (UniqueConstraintViolationException) {
            $mapping->refresh();

            throw new MappingConflict($this->holder($mapping, $internalType, $internalId) ?? $mapping);
        }

        return $mapping;
    }

    public function unlink(Mapping $mapping, string $status = Mapping::UNMATCHED): Mapping
    {
        $mapping->forceFill(['internal_type' => null, 'internal_id' => null, 'status' => $status])->save();

        return $mapping;
    }

    public function mark(Mapping $mapping, string $status): Mapping
    {
        $mapping->forceFill(['status' => $status])->save();

        return $mapping;
    }

    /**
     * Whether a change stamped $updatedAt is older than what this mapping
     * has already applied (an out-of-order delivery).
     */
    public function isStale(Mapping $mapping, ?CarbonInterface $updatedAt): bool
    {
        return $updatedAt !== null
            && $mapping->external_updated_at !== null
            && $updatedAt->lt($mapping->external_updated_at);
    }

    /**
     * Record that the change stamped $updatedAt was applied (never moves
     * backwards).
     */
    public function touch(Mapping $mapping, ?CarbonInterface $updatedAt): Mapping
    {
        if ($updatedAt !== null && ($mapping->external_updated_at === null || $updatedAt->gt($mapping->external_updated_at))) {
            $mapping->external_updated_at = $updatedAt;
        }

        $mapping->synced_at = now();
        $mapping->save();

        return $mapping;
    }

    private function holder(Mapping $mapping, string $internalType, int $internalId): ?Mapping
    {
        return Mapping::query()
            ->where('connection_id', $mapping->connection_id)
            ->where('entity', $mapping->entity)
            ->where('internal_type', $internalType)
            ->where('internal_id', $internalId)
            ->first();
    }
}
