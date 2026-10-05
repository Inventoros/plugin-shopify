<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Outbox;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\OutboxEntry;

/**
 * A coalescing outbox for outbound pushes.
 *
 * put() records that a subject (an item, a product) needs pushing. Ten
 * changes to the same subject before the push runs leave one row (its
 * `coalesced` counter says how many were folded in). The push job reads
 * due rows, computes the CURRENT state of each subject, sends it, and
 * calls done(): a row changed again after it was read is kept for the
 * next push, so no change is lost.
 */
final class Outbox
{
    /**
     * @param  array<string, mixed>  $payload  merged into the row's payload
     */
    public function put(Connection $connection, string $kind, string $subject, array $payload = [], int $delaySeconds = 0): OutboxEntry
    {
        for ($try = 0; $try < 2; $try++) {
            $existing = OutboxEntry::query()
                ->where('connection_id', $connection->id)
                ->where('kind', $kind)
                ->where('subject', $subject)
                ->first();

            if ($existing !== null) {
                OutboxEntry::query()->whereKey($existing->id)->update([
                    'coalesced' => DB::raw('coalesced + 1'),
                    'payload' => json_encode(array_merge($existing->payload ?? [], $payload)),
                    'updated_at' => now(),
                ]);

                return $existing->refresh();
            }

            try {
                return DB::transaction(fn () => OutboxEntry::create([
                    'organization_id' => $connection->organization_id,
                    'connection_id' => $connection->id,
                    'kind' => $kind,
                    'subject' => $subject,
                    'payload' => $payload === [] ? null : $payload,
                    'coalesced' => 0,
                    'available_at' => now()->addSeconds($delaySeconds),
                ]));
            } catch (UniqueConstraintViolationException) {
                // A concurrent put created it: fold into that row.
            }
        }

        throw new \RuntimeException("Could not queue {$kind} {$subject}.");
    }

    /**
     * @return Collection<int, OutboxEntry>
     */
    public function due(Connection $connection, string $kind, int $limit = 100): Collection
    {
        return OutboxEntry::query()
            ->where('connection_id', $connection->id)
            ->where('kind', $kind)
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Remove a pushed row, unless it was changed again since it was read.
     */
    public function done(OutboxEntry $entry): bool
    {
        return OutboxEntry::query()
            ->whereKey($entry->id)
            ->where('coalesced', $entry->coalesced)
            ->delete() === 1;
    }

    public function postpone(OutboxEntry $entry, int $seconds): void
    {
        OutboxEntry::query()->whereKey($entry->id)->update(['available_at' => now()->addSeconds($seconds)]);
    }

    public function pending(Connection $connection, ?string $kind = null): int
    {
        return OutboxEntry::query()
            ->where('connection_id', $connection->id)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->count();
    }

    public function hasDue(Connection $connection, string $kind): bool
    {
        return $this->due($connection, $kind, 1)->isNotEmpty();
    }
}
