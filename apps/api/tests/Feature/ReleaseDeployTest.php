<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ReleaseDeployTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private string $token;

    private string $repo;

    private string $bare;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        Membership::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->token = $user->createToken('t')->plainTextToken;

        $this->repo = sys_get_temp_dir().'/wwc-rel-'.uniqid();
        $this->bare = sys_get_temp_dir().'/wwc-rel-bare-'.uniqid();
        mkdir($this->repo, 0777, true);
        $this->git($this->repo, ['init', '-b', 'main']);
        $this->git($this->repo, ['config', 'user.email', 'test@wwc.local']);
        $this->git($this->repo, ['config', 'user.name', 'WWC Test']);
        file_put_contents($this->repo.'/README.md', "one\n");
        $this->git($this->repo, ['add', 'README.md']);
        $this->git($this->repo, ['commit', '-m', 'first']);
        $this->git($this->repo, ['clone', '--bare', $this->repo, $this->bare]);
        $this->git($this->repo, ['remote', 'add', 'origin', $this->bare]);
        $this->git($this->repo, ['push', '-u', 'origin', 'main']);

        config(['wwc.repo_path' => $this->repo, 'wwc.deploy_remote' => 'origin', 'wwc.deploy_branch' => 'main']);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->repo);
        $this->rmdir($this->bare);
        parent::tearDown();
    }

    public function test_dashboard_includes_release_version(): void
    {
        $res = $this->withToken($this->token)->getJson('/api/dashboard')->assertOk();
        $this->assertTrue($res->json('release.repo_available'));
        $this->assertNotEmpty($res->json('release.version'));
        $this->assertNotEmpty($res->json('release.git.short_sha'));
        $this->assertSame('first', $res->json('release.git.subject'));
    }

    public function test_deploy_fast_forwards_from_origin(): void
    {
        $work = sys_get_temp_dir().'/wwc-rel-work-'.uniqid();
        $this->git(null, ['clone', $this->bare, $work]);
        $this->git($work, ['config', 'user.email', 'test@wwc.local']);
        $this->git($work, ['config', 'user.name', 'WWC Test']);
        file_put_contents($work.'/README.md', "two\n");
        $this->git($work, ['add', 'README.md']);
        $this->git($work, ['commit', '-m', 'second']);
        $this->git($work, ['push', 'origin', 'main']);

        $this->withToken($this->token)->getJson('/api/release')
            ->assertOk()
            ->assertJsonPath('data.git.update_available', true);

        $this->withToken($this->token)->postJson('/api/release/deploy')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame("two\n", file_get_contents($this->repo.'/README.md'));
        $this->rmdir($work);
    }

    public function test_technician_cannot_deploy(): void
    {
        $tech = User::factory()->create(['current_organization_id' => $this->org->id]);
        Membership::create(['organization_id' => $this->org->id, 'user_id' => $tech->id, 'role' => 'technician']);
        $token = $tech->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/release/deploy')->assertForbidden();
    }

    /** @param  list<string>  $args */
    private function git(?string $cwd, array $args): void
    {
        $pending = Process::timeout(20);
        if ($cwd) {
            $pending = $pending->path($cwd);
        }
        $result = $pending->run(array_merge(['git', '-c', 'safe.directory=*'], $args));
        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
