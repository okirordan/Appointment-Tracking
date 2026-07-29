<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_publicly_accessible_with_correct_identity()
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => 'Assignment Tracking System',
                'short_name' => 'ATS',
                'start_url' => '/',
                'scope' => '/',
                'display' => 'standalone',
                'display_override' => ['window-controls-overlay', 'standalone'],
            ]);
    }

    public function test_manifest_icons_exist_on_disk()
    {
        $icons = $this->get('/manifest.webmanifest')->json('icons');

        $this->assertNotEmpty($icons);

        foreach ($icons as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }
    }

    public function test_service_worker_and_offline_assets_exist()
    {
        $this->assertFileExists(public_path('service-worker.js'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertFileExists(public_path('pwa/offline.js'));
    }

    public function test_layout_emits_pwa_tags_only_when_enabled()
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<link rel="manifest"', false)
            ->assertSee('<meta name="ats-pwa" content="enabled">', false);

        config(['pwa.enabled' => false]);

        // The Ziggy route dump still mentions the manifest URI, so assert
        // specifically on the tags the layout emits.
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('<link rel="manifest"', false)
            ->assertDontSee('name="ats-pwa"', false);
    }
}
