<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One external account (a store) connected by one organization.
 *
 * Credentials are an encrypted array (APP_KEY), hidden from serialization,
 * and never logged, flashed or sent to the browser: use credential() in
 * adapters and hasCredential() in page props.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $acting_user_id
 * @property string $name
 * @property string $shop_domain
 * @property array<string, mixed>|null $credentials
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $remote
 * @property \Illuminate\Support\Carbon|null $last_tested_at
 * @property \Illuminate\Support\Carbon|null $last_success_at
 * @property \Illuminate\Support\Carbon|null $last_failure_at
 * @property string|null $last_error
 * @property int $consecutive_failures
 * @property \Illuminate\Support\Carbon|null $paused_until
 */
class Connection extends KitModel
{
    protected const TABLE = 'connections';

    /** Saved, not yet tested. */
    public const PENDING = 'pending';

    /** Tested and syncing. */
    public const CONNECTED = 'connected';

    /** Credentials rejected or the test failed: syncing stops until a test passes. */
    public const ERROR = 'error';

    /** The store removed the app (or the user disconnected): history kept, syncing off. */
    public const DISCONNECTED = 'disconnected';

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'remote' => 'array',
            'last_tested_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'paused_until' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasCredential(string $key): bool
    {
        return $this->credential($key) !== null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    /**
     * Whether background work may call the provider now: connected and the
     * circuit breaker is closed.
     */
    public function canSync(): bool
    {
        return $this->status === self::CONNECTED && ! $this->isPaused();
    }

    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    /**
     * Every credential value, so log and error messages can be scrubbed of
     * them (see SyncRecorder::scrub()).
     *
     * @return array<int, string>
     */
    public function secrets(): array
    {
        $values = [];

        foreach ($this->credentials ?? [] as $value) {
            if (is_string($value) && strlen($value) >= 4) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'connection_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(Mapping::class, 'connection_id');
    }
}
