<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Mapping;

use Inventoros\Plugins\Shopify\Kit\Models\Mapping;
use RuntimeException;

/**
 * The core record is already mapped to another external record of the
 * same connection and entity.
 */
final class MappingConflict extends RuntimeException
{
    public function __construct(public readonly Mapping $existing)
    {
        parent::__construct("Already mapped to {$existing->entity} {$existing->external_id}".
            ($existing->external_label ? " ({$existing->external_label})" : '').'.');
    }
}
