<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Inventoros\Plugins\Shopify\Kit\Jobs\IntegrationJob;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Models\SyncLog;
use Inventoros\Plugins\Shopify\Kit\Sync\SyncRecorder;
use Inventoros\Plugins\Shopify\Shopify\ClientFactory;
use Inventoros\Plugins\Shopify\Sync\WebhookProcessor;

/**
 * Subscribes the store's webhooks to this connection's inbox URL. Runs
 * after a connection test passes. Shopify can only deliver to a public
 * https URL; on any other APP_URL (local development) it records why and
 * stops, and the scheduled incremental sync keeps data flowing.
 */
class RegisterWebhooks extends IntegrationJob
{
    public function __construct(public int $connectionKey) {}

    public function connectionId(): int
    {
        return $this->connectionKey;
    }

    public function arguments(): array
    {
        return [$this->connectionKey];
    }

    public function reference(): ?string
    {
        return 'webhook subscriptions';
    }

    public static function callbackUrl(Connection $connection): string
    {
        return route('shopify.webhooks.receive', ['connection' => $connection->id]);
    }

    protected function perform(Connection $connection): void
    {
        $url = self::callbackUrl($connection);
        $recorder = app(SyncRecorder::class);
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || $host === 'localhost' || str_ends_with($host, '.test') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $recorder->log($connection, null, SyncLog::WARNING, "Webhooks were not registered: Shopify needs a public https address and this site is at {$url}. The scheduled sync still picks up changes every 15 minutes.", ['action' => 'webhooks']);

            return;
        }

        $client = app(ClientFactory::class)->make($connection);

        foreach (WebhookProcessor::TOPICS as $topic) {
            $client->subscribeWebhook($topic, $url);
        }

        $connection->forceFill(['remote' => array_merge($connection->remote ?? [], ['webhooks_registered_at' => now()->toIso8601String()])])->save();

        $recorder->log($connection, null, SyncLog::INFO, 'Subscribed '.count(WebhookProcessor::TOPICS).' Shopify webhook topics.', ['action' => 'webhooks']);
    }
}
