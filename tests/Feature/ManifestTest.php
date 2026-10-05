<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Starter\Tests\Feature;

use App\Services\Plugins\PluginRequirements;
use Inventoros\Plugins\Starter\StarterPlugin;
use Inventoros\Plugins\Starter\Tests\TestCase;

final class ManifestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/plugin.json'), true);
        $this->assertIsArray($manifest, 'plugin.json must be a JSON object');

        return $manifest;
    }

    public function test_the_folder_is_named_after_the_slug(): void
    {
        $this->assertSame(StarterPlugin::SLUG, basename(dirname(__DIR__, 2)));
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{0,63}$/', StarterPlugin::SLUG, 'marketplace slug rule');
    }

    public function test_the_manifest_has_the_required_fields_and_a_semantic_version(): void
    {
        $manifest = $this->manifest();

        foreach (['name', 'description', 'version', 'author', 'requires', 'requires_php', 'main_file'] as $field) {
            $this->assertNotEmpty($manifest[$field] ?? null, "plugin.json needs \"{$field}\"");
        }

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $manifest['version']);
        $this->assertSame('Inventoros\\Plugins\\Starter', $manifest['inventoros']['namespace']);
        $this->assertSame('plg_starter_', $manifest['inventoros']['table_prefix']);
    }

    public function test_this_inventoros_meets_the_declared_requirements(): void
    {
        PluginRequirements::assertMet($this->manifest(), 'Starter');
        $this->addToAssertionCount(1);
    }

    public function test_the_ui_bundle_is_built(): void
    {
        $ui = $this->manifest()['ui'];
        $dist = dirname(__DIR__, 2).'/dist/';

        $this->assertFileExists($dist.$ui['entry'], 'run: cd ui && npm ci && npm run build');
        foreach ($ui['styles'] as $style) {
            $this->assertFileExists($dist.$style);
        }
    }

    public function test_every_table_the_plugin_creates_carries_its_prefix(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
            $this->assertStringContainsString('_plg_starter_', basename($file), 'migration file names carry the prefix');

            preg_match_all("/Schema::create\\('([^']+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $table) {
                $this->assertStringStartsWith('plg_starter_', $table);
            }
        }
    }
}
