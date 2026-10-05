<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Support;

use Inventoros\Plugins\Shopify\Models\Setting;

/**
 * Per-organization settings, stored in the plugin's own table so uninstalling
 * the plugin removes them with the table.
 */
final class Settings
{
    /**
     * Every setting and its default. Unknown keys are never stored.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'greeting' => 'Hello from Shopify',
        'log_low_stock' => false,
    ];

    private function __construct(private readonly int $organizationId) {}

    public static function for(int $organizationId): self
    {
        return new self($organizationId);
    }

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown Shopify setting \"{$key}\".");
        }

        $row = Setting::query()
            ->where('organization_id', $this->organizationId)
            ->where('key', $key)
            ->first();

        return $row === null ? self::DEFAULTS[$key] : $row->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = Setting::query()
            ->where('organization_id', $this->organizationId)
            ->get()
            ->mapWithKeys(fn (Setting $row) => [$row->key => $row->value])
            ->all();

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            Setting::query()->updateOrCreate(
                ['organization_id' => $this->organizationId, 'key' => $key],
                ['value' => $value],
            );
        }
    }
}
