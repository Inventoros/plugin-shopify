<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Webhooks;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;

/**
 * The idempotent webhook inbox.
 *
 * The controller verifies the signature, then calls accept(): the delivery
 * is stored once per provider event id and once per (topic, body hash), so
 * redeliveries and replays are recognised and acknowledged without being
 * processed again. Processing happens in a queued job that must claim()
 * the entry first; a claim succeeds once, so even two workers running the
 * same job process an entry exactly once.
 */
final class WebhookInbox
{
    /** A claim on an entry left in processing longer than this is considered abandoned. */
    public const STALE_CLAIM_MINUTES = 15;

    /**
     * @return array{0: WebhookInboxEntry, 1: bool} the entry, and whether it was a duplicate
     */
    public function accept(
        Connection $connection,
        string $topic,
        string $eventId,
        string $body,
        ?string $resourceId = null,
        ?CarbonInterface $triggeredAt = null,
    ): array {
        $hash = hash('sha256', $body);

        $existing = $this->existing($connection, $topic, $eventId, $hash);
        if ($existing !== null) {
            return [$existing, true];
        }

        try {
            // A savepoint inside any outer transaction, so a unique violation
            // (a concurrent duplicate) does not abort it on PostgreSQL.
            $entry = DB::transaction(fn () => WebhookInboxEntry::create([
                'organization_id' => $connection->organization_id,
                'connection_id' => $connection->id,
                'topic' => $topic,
                'event_id' => mb_substr($eventId, 0, 191),
                'payload_hash' => $hash,
                'resource_id' => $resourceId === null ? null : mb_substr($resourceId, 0, 191),
                'payload' => $body,
                'triggered_at' => $triggeredAt,
                'status' => WebhookInboxEntry::RECEIVED,
            ]));
        } catch (UniqueConstraintViolationException) {
            $existing = $this->existing($connection, $topic, $eventId, $hash);

            if ($existing === null) {
                throw new \RuntimeException('Webhook delivery collided with an entry that cannot be found.');
            }

            return [$existing, true];
        }

        return [$entry, false];
    }

    /**
     * Take the entry for processing. True exactly once per delivery (and
     * again after it failed, or when a retry from the dead-letter list
     * brings it back).
     */
    public function claim(WebhookInboxEntry $entry): bool
    {
        $claimed = WebhookInboxEntry::query()
            ->whereKey($entry->id)
            ->where(function ($query) {
                $query->whereIn('status', [WebhookInboxEntry::RECEIVED, WebhookInboxEntry::FAILED, WebhookInboxEntry::DEAD])
                    ->orWhere(fn ($q) => $q->where('status', WebhookInboxEntry::PROCESSING)
                        ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES)));
            })
            ->update([
                'status' => WebhookInboxEntry::PROCESSING,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $entry->refresh();

            return true;
        }

        return false;
    }

    public function processed(WebhookInboxEntry $entry): void
    {
        $this->finish($entry, WebhookInboxEntry::PROCESSED, null);
    }

    public function ignored(WebhookInboxEntry $entry, string $reason): void
    {
        $this->finish($entry, WebhookInboxEntry::IGNORED, $reason);
    }

    public function failed(WebhookInboxEntry $entry, string $error): void
    {
        $entry->forceFill(['status' => WebhookInboxEntry::FAILED, 'last_error' => mb_substr($error, 0, 2000)])->save();
    }

    public function dead(WebhookInboxEntry $entry, string $error): void
    {
        $entry->forceFill(['status' => WebhookInboxEntry::DEAD, 'last_error' => mb_substr($error, 0, 2000)])->save();
    }

    public function depth(Connection $connection): int
    {
        return WebhookInboxEntry::query()
            ->where('connection_id', $connection->id)
            ->whereIn('status', [WebhookInboxEntry::RECEIVED, WebhookInboxEntry::PROCESSING, WebhookInboxEntry::FAILED])
            ->count();
    }

    private function finish(WebhookInboxEntry $entry, string $status, ?string $note): void
    {
        $entry->forceFill([
            'status' => $status,
            'last_error' => $note === null ? null : mb_substr($note, 0, 2000),
            'processed_at' => now(),
        ])->save();
    }

    private function existing(Connection $connection, string $topic, string $eventId, string $hash): ?WebhookInboxEntry
    {
        return WebhookInboxEntry::query()
            ->where('connection_id', $connection->id)
            ->where(function ($query) use ($topic, $eventId, $hash) {
                $query->where('event_id', mb_substr($eventId, 0, 191))
                    ->orWhere(fn ($q) => $q->where('topic', $topic)->where('payload_hash', $hash));
            })
            ->first();
    }
}
