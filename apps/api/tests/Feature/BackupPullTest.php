<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Services\BackupPullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupPullTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulls_split_backup_parts_into_directory(): void
    {
        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $site = Site::create([
            'organization_id' => $org->id,
            'name' => 'Cuno',
            'url' => 'https://cuno.example',
            'status' => 'active',
            'paired_at' => now(),
        ]);
        $site->setHmacSecret('secret');
        $site->save();

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_ends_with($url, '/backups/latest-full/parts')) {
                return Http::response([
                    'ok' => true,
                    'backup_id' => 'full-20260823',
                    'type' => 'full',
                    'created_at' => '2026-08-23T10:00:00Z',
                    'wp_version' => '7.1',
                    'file_count' => 3,
                    'files' => [
                        ['name' => 'manifest.json', 'size' => 0],
                        ['name' => 'database.sql', 'size' => 0],
                        ['name' => 'files.zip', 'size' => 0],
                    ],
                ]);
            }
            if (str_contains($url, '/parts/manifest.json')) {
                return Http::response('{"id":"full-1"}', 200);
            }
            if (str_contains($url, '/parts/database.sql')) {
                return Http::response('-- sql --'."\n", 200);
            }
            if (str_contains($url, '/parts/files.zip')) {
                return Http::response('PK-fake', 200);
            }

            return Http::response(['ok' => false], 404);
        });

        $record = app(BackupPullService::class)->pullLatestFull($site);

        $this->assertSame('full-20260823', $record->backup_id);
        $this->assertSame('stored', $record->status);
        $this->assertDirectoryExists($record->storage_path);
        $this->assertFileExists($record->storage_path.'/database.sql');
        $this->assertFileExists($record->storage_path.'/files.zip');
        $this->assertSame('-- sql --'."\n", file_get_contents($record->storage_path.'/database.sql'));
    }
}
