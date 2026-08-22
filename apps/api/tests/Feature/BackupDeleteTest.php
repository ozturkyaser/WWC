<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_can_delete_stored_backup_without_agent(): void
    {
        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $site = Site::create([
            'organization_id' => $org->id,
            'name' => 'Cuno',
            'url' => 'https://cuno.example',
            'status' => 'pending',
            'health' => [
                'backups' => [
                    ['id' => 'full-old-1', 'type' => 'full'],
                    ['id' => 'full-keep', 'type' => 'full'],
                ],
            ],
        ]);

        $dir = storage_path('app/wwc-backups/'.$site->id);
        mkdir($dir, 0755, true);
        $path = $dir.'/full-old-1.zip';
        file_put_contents($path, 'zip');

        SiteBackup::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'backup_id' => 'full-old-1',
            'type' => 'full',
            'status' => 'stored',
            'size_bytes' => 3,
            'storage_path' => $path,
        ]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson('/api/sites/'.$site->id.'/backups/full-old-1')
            ->assertOk()
            ->assertJson(['ok' => true, 'deleted' => 'full-old-1']);

        $this->assertFalse(is_file($path));
        $this->assertDatabaseMissing('site_backups', ['backup_id' => 'full-old-1']);
        $this->assertSame([['id' => 'full-keep', 'type' => 'full']], $site->fresh()->health['backups']);
    }
}
