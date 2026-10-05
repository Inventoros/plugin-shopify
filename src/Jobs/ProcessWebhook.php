<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Inventoros\Plugins\Shopify\Kit\Jobs\IntegrationJob;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\DeadLetter;
use Inventoros\Plugins\Shopify\Kit\Models\WebhookInboxEntry;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Kit\Webhooks\WebhookInbox;
use Inventoros\Plugins\Shopify\Sync\WebhookProcessor;
use Throwable;

/**
 * Processes one inbox entry: claims it (exactly once), applies it, and
 * records a webhook sync run. Failures go through the kit's retry policy;
 * an entry that gives up is marked dead and listed with its error.
 */
class ProcessWebhook extends IntegrationJob
{
    public function __construct(public int $connectionKey, public int $entry) {}

    public function connectionId(): int
    {
        return $this->connectionKey;
    }

    public function arguments(): array
    {
        return [$this->connectionKey, $this->entry];
    }

    public function reference(): ?string
    {
        $entry = WebhookInboxEntry::query()->find($this->entry);

        return $entry === null ? "webhook #{$this->entry}" : "{$entry->topic} #{$entry->id}";
    }

    /** The app/uninstalled topic must be applied even when the connection cannot sync. */
    protected function requiresSyncableConnection(): bool
    {
        return false;
    }

    protected function perform(Connection $connection): void
    {
        $entry = WebhookInboxEntry::query()
            ->where('connection_id', $connection->id)
            ->find($this->entry);

        if ($entry === null) {
            return;
        }

        $inbox = app(WebhookInbox::class);

        if ($entry->topic !== WebhookProcessor::UNINSTALLED && ! $connection->canSync()) {
            if ($connection->status === Connection::DISCONNECTED && $entry->status === WebhookInboxEntry::RECEIVED) {
                $inbox->ignored($entry, 'The connection is disconnected.');
            }

            // Paused or in error: left in the inbox for the scheduled sync.
            return;
        }

        if (! $inbox->claim($entry)) {
            return;
        }

        $recorder = app(SyncRecorder::class);
        $run = $recorder->start($connection, 'webhook', $entry->topic, 'webhook');

        try {
            $ignored = app(WebhookProcessor::class)->process($connection, $entry, $run);
        } catch (Throwable $error) {
            $recorder->finish($run, $error);
            $inbox->failed($entry, SyncRecorder::scrub($connection, $error->getMessage()));

            throw $error;
        }

        $recorder->finish($run);

        $ignored === null ? $inbox->processed($entry) : $inbox->ignored($entry, $ignored);
    }

    protected function deadLettered(Connection $connection, DeadLetter $letter, Throwable $error): void
    {
        $entry = WebhookInboxEntry::query()->where('connection_id', $connection->id)->find($this->entry);

        if ($entry !== null) {
            app(WebhookInbox::class)->dead($entry, $letter->error);
        }
    }
}
