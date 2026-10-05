<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Inventoros\Plugins\Shopify\Jobs\ProcessWebhook;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Webhooks\HmacSignature;
use Inventoros\Plugins\Shopify\Kit\Webhooks\WebhookInbox;
use Inventoros\Plugins\Shopify\Sync\WebhookProcessor;
use Throwable;

/**
 * POST /webhooks/plugins/shopify/{connection}
 *
 * Outside the web group (no session, no CSRF). In order:
 *  1. the connection exists and has a webhook secret, else 404;
 *  2. X-Shopify-Hmac-Sha256 matches the raw body, else 401 and nothing
 *     is stored;
 *  3. the X-Shopify-Shop-Domain header names this connection's shop;
 *  4. the delivery goes into the inbox (a duplicate event id or body is
 *     acknowledged and dropped);
 *  5. a job is queued to process it, and Shopify gets 200 at once.
 * The request never waits on processing, so Shopify's 5 second timeout is
 * never at risk and a slow import never causes a redelivery storm.
 */
final class WebhookController extends Controller
{
    public function __invoke(Request $request, int $connection): JsonResponse
    {
        $record = Connection::query()->find($connection);

        if ($record === null || $record->status === Connection::DISCONNECTED || ! $record->hasCredential('webhook_secret')) {
            return response()->json(['message' => 'Unknown connection.'], 404);
        }

        $body = $request->getContent();

        if (! HmacSignature::matches($body, $record->credential('webhook_secret'), $request->header('X-Shopify-Hmac-Sha256'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $shop = strtolower((string) $request->header('X-Shopify-Shop-Domain'));
        if ($shop !== '' && $shop !== strtolower($record->shop_domain)) {
            return response()->json(['message' => 'Wrong shop.'], 401);
        }

        $topic = strtolower((string) $request->header('X-Shopify-Topic'));
        if ($topic === '' || json_decode($body, true) === null) {
            return response()->json(['message' => 'Missing topic or body.'], 400);
        }

        if (! array_key_exists($topic, WebhookProcessor::TOPICS)) {
            return response()->json(['message' => 'Topic not handled.'], 200);
        }

        $eventId = (string) ($request->header('X-Shopify-Event-Id') ?: $request->header('X-Shopify-Webhook-Id') ?: hash('sha256', $topic.$body));
        $triggeredAt = $this->time($request->header('X-Shopify-Triggered-At'));
        $data = json_decode($body, true);
        $resourceId = is_array($data) && isset($data['id']) ? (string) $data['id'] : (isset($data['inventory_item_id']) ? $data['inventory_item_id'].':'.($data['location_id'] ?? '') : null);

        [$entry, $duplicate] = app(WebhookInbox::class)->accept($record, $topic, $eventId, $body, $resourceId, $triggeredAt);

        if ($duplicate) {
            return response()->json(['message' => 'Already received.'], 200);
        }

        ProcessWebhook::dispatch($record->id, $entry->id);

        return response()->json(['message' => 'Received.'], 200);
    }

    private function time(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
