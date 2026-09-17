<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the non-default project layout (see AGENTS.md): the Laravel app lives
 * in laravel/, while the web root (public_html/) and storage/ are siblings at
 * the project root. These assertions fail if the bootstrap path overrides in
 * bootstrap/app.php are lost.
 */
class StructureHealthTest extends TestCase
{
    public function test_health_endpoint_boots_the_http_stack(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_base_path_is_the_laravel_directory(): void
    {
        $this->assertSame('laravel', basename(base_path()));
    }

    public function test_public_path_points_to_sibling_public_html(): void
    {
        $projectRoot = dirname(base_path());

        $this->assertSame($projectRoot.DIRECTORY_SEPARATOR.'public_html', public_path());
    }

    public function test_storage_path_points_to_sibling_storage(): void
    {
        $projectRoot = dirname(base_path());

        $this->assertSame($projectRoot.DIRECTORY_SEPARATOR.'storage', storage_path());
    }
}
