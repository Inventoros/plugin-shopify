<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Kit\Connections;

use Inventoros\Plugins\Shopify\Kit\Models\Connection;

/**
 * Checks that a connection's credentials work, with the cheapest
 * authenticated call the provider offers. Implemented per provider and
 * bound in the container; run only from the TestConnection job.
 */
interface ConnectionTester
{
    public function test(Connection $connection): TestResult;
}
