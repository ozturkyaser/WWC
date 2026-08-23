<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\User;
use App\Services\CloneLogReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CloneLogReviewTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->site = Site::create([
            'organization_id' => $org->id,
            'name' => 'Cuno',
            'url' => 'https://cuno.example',
            'status' => 'active',
        ]);
    }

    public function test_clean_logs_send_all_clear(): void
    {
        config(['wwc.ai_api_key' => null]);

        $review = app(CloneLogReviewService::class)->reviewAndNotify($this->site, [
            'ok' => true,
            'items' => [['type' => 'plugin', 'slug' => 'akismet', 'ok' => true]],
            'health_error' => null,
        ], "AH00558: apache2: Could not reliably determine\n[notice] AH00094: Command line: 'apache2'\n");

        $this->assertTrue($review['ok']);
        $this->assertSame('heuristic', $review['source']);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->severity === 'info'
            && str_contains($mail->alertSubject, 'ohne Fehler'));
        $this->assertSame(1, SiteEvent::where('type', 'alert_clone_dry_run_ok')->count());
    }

    public function test_fatal_in_logs_blocks_all_clear_even_if_ai_says_ok(): void
    {
        config(['wwc.ai_api_key' => 'test-key', 'wwc.ai_api_base' => 'https://api.openai.com/v1']);
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"ok":true,"summary":"Alles gut","findings":[]}'],
                ]],
            ]),
        ]);

        $review = app(CloneLogReviewService::class)->reviewAndNotify($this->site, [
            'ok' => true,
            'items' => [['type' => 'plugin', 'slug' => 'akismet', 'ok' => true]],
        ], "PHP Fatal error: Uncaught Error: Call to undefined function foo() in wp-content/plugins/akismet/akismet.php:12");

        $this->assertFalse($review['ok']);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->severity === 'warning');
        Mail::assertNotSent(AlertMail::class, fn (AlertMail $mail) => $mail->severity === 'info');
    }

    public function test_ai_json_is_used_when_logs_are_clean(): void
    {
        config(['wwc.ai_api_key' => 'test-key', 'wwc.ai_api_base' => 'https://api.openai.com/v1']);
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"ok":true,"summary":"Keine Fehler in der Kopie.","findings":[]}'],
                ]],
            ]),
        ]);

        $review = app(CloneLogReviewService::class)->reviewAndNotify($this->site, [
            'ok' => true,
            'items' => [['type' => 'theme', 'slug' => 'twentytwentyfour', 'ok' => true]],
        ], '');

        $this->assertTrue($review['ok']);
        $this->assertSame('ai', $review['source']);
        $this->assertSame('Keine Fehler in der Kopie.', $review['summary']);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => str_contains($mail->alertSubject, 'ohne Fehler'));
    }
}
