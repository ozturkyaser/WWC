<?php

namespace Tests\Feature;

use App\Services\PluginPackager;
use Tests\TestCase;

class PluginPackagerTest extends TestCase
{
    public function test_prefers_packages_agent_over_stale_resources(): void
    {
        $repo = dirname(base_path(), 2);
        if (! is_file($repo.'/packages/wp-agent/wwc-agent.php')) {
            $repo = '/repo';
        }
        config(['wwc.repo_path' => $repo]);

        $packager = app(PluginPackager::class);
        $source = $packager->sourcePath();
        $this->assertStringContainsString('packages/wp-agent', $source);
        $this->assertSame('0.6.4', $packager->version());
    }
}
