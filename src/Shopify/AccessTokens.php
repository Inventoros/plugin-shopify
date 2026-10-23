<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inventoros\Plugins\Shopify\Kit\Exceptions\PermanentError;
use Inventoros\Plugins\Shopify\Kit\Exceptions\RateLimited;
use Inventoros\Plugins\Shopify\Kit\Exceptions\TransientError;
use Inventoros\Plugins\Shopify\Kit\Models\Connection;
use Inventoros\Plugins\Shopify\Kit\Support\RetryAfter;

/** Tokens for apps and stores owned by the same Shopify organization. */
final class AccessTokens
{
    public function token(Connection $connection): string
    {
        if (! ShopDomain::valid($connection->shop_domain)) {
            throw new PermanentError('The shop domain is not a myshopify.com address.');
        }
        if ($connection->setting('auth_mode', 'token') !== 'client_credentials') {
            return $connection->credential('access_token')
                ?? throw new PermanentError('This connection has no access token. Add one in the connection settings.', authentication: true);
        }

        $token = DB::transaction(function () use ($connection) {
            $locked = Connection::forOrganization($connection->organization_id)->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $credentials = $locked->credentials ?? [];
            $expires = $credentials['token_expires_at'] ?? null;
            if (is_string($expires) && $locked->hasCredential('access_token') && Carbon::parse($expires)->gt(now()->addMinutes(2))) {
                return $locked->credential('access_token');
            }
            if (! $locked->hasCredential('client_id') || ! $locked->hasCredential('webhook_secret')) {
                throw new PermanentError('Add the app client ID and API secret key for client-credentials authentication.', authentication: true);
            }
            try {
                $response = Http::asForm()->acceptJson()->timeout(30)->connectTimeout(10)
                    ->withOptions(['allow_redirects' => false])
                    ->post('https://'.$locked->shop_domain.'/admin/oauth/access_token', [
                        'grant_type' => 'client_credentials', 'client_id' => $locked->credential('client_id'),
                        'client_secret' => $locked->credential('webhook_secret'),
                    ]);
            } catch (ConnectionException) {
                throw new TransientError('Could not reach Shopify to renew the access token.');
            }
            if ($response->status() === 429) {
                throw new RateLimited('Shopify limited token requests.', RetryAfter::seconds($response->header('Retry-After'), 5));
            }
            if ($response->serverError()) {
                throw new TransientError('Shopify could not renew the access token. Retry later.');
            }
            if (! $response->successful()) {
                throw new PermanentError('Shopify refused the app credentials. Check the client ID/secret, app installation and that the app and store belong to the same Shopify organization.', authentication: true);
            }
            $body = $response->json();
            if (! is_array($body) || ! is_string($body['access_token'] ?? null) || $body['access_token'] === ''
                || ! is_numeric($body['expires_in'] ?? null) || (int) $body['expires_in'] <= 120) {
                throw new TransientError('Shopify returned an invalid token response. Retry the connection test.');
            }
            $locked->forceFill(['credentials' => array_replace($credentials, [
                'access_token' => $body['access_token'], 'token_expires_at' => now()->addSeconds((int) $body['expires_in'])->toIso8601String(),
            ])])->save();

            return $body['access_token'];
        });
        $connection->refresh(); // Error/log scrubbing sees the newly minted secret.

        return $token;
    }
}
