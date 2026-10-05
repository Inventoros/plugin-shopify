<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads a Retry-After header: delta seconds ("2", or "2.0" as Shopify sends
 * it) or an HTTP date. Unreadable or missing values fall back to $default.
 */
final class RetryAfter
{
    public static function seconds(?string $header, int $default = 2): int
    {
        $header = trim((string) $header);

        if ($header === '') {
            return $default;
        }

        if (is_numeric($header)) {
            return max(1, (int) ceil((float) $header));
        }

        try {
            $at = Carbon::parse($header);
        } catch (Throwable) {
            return $default;
        }

        return max(1, (int) ceil(now()->diffInSeconds($at, false)));
    }
}
