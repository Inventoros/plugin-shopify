<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use App\Models\Plugin;
use App\Services\PluginService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class ConsoleActivationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_supports_console_exception_handler_decorators(): void
    {
        // The exception contract does not expose Laravel's dontFlash method.
        app()->instance(ExceptionHandler::class, Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing());
        try {
            app(PluginService::class)->activatePlugin('shopify');
            $this->assertTrue((bool) Plugin::where('slug', 'shopify')->value('is_active'));
        } finally {
            app(PluginService::class)->deactivatePlugin('shopify');
        }
    }
}
