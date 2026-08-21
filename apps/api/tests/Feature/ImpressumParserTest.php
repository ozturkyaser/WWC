<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImpressumParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_tmg_impressum_with_spaced_vat_and_allcaps_company(): void
    {
        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $token = $user->createToken('t')->plainTextToken;

        $home = '<html><body><a href="/impressum/">Impressum</a></body></html>';
        $impressum = <<<'HTML'
<!doctype html><html lang="de"><body>
<h2>Impressum</h2>
<p><strong>Angaben gemäß § 5 TMG</strong><br>
CUNO Fleetservices GmbH<br>
Joachimsthaler Str. 19<br>
10719 Berlin</p>
<p>Handelsregister: HRB 124 680 B<br>
Registergericht: Amtsgericht Berlin-Charlottenburg</p>
<p><strong>Kontakt</strong><br>
E-Mail: info@cuno.gmbh</p>
<p><strong>Umsatzsteuer-ID</strong><br>
Umsatzsteuer-Identifikationsnummer gemäß § 27 a Umsatzsteuergesetz: DE 26 88 71 416</p>
<p><strong>Haftung für Inhalte</strong><br>Lorem</p>
</body></html>
HTML;

        Http::fake(function ($request) use ($home, $impressum) {
            $url = (string) $request->url();
            if (str_contains($url, 'api.openai.com')) {
                return Http::response(['error' => 'no'], 401);
            }
            if (str_contains($url, 'impressum')) {
                return Http::response($impressum, 200);
            }
            if (str_contains($url, 'cuno.gmbh')) {
                return Http::response($home, 200);
            }

            return Http::response('missing', 404);
        });

        $res = $this->withToken($token)->postJson('/api/onboarding/impressum', [
            'site_url' => 'https://cuno.gmbh',
        ])->assertOk();

        $this->assertTrue($res->json('ok'));
        $this->assertSame('CUNO Fleetservices GmbH', $res->json('client.company'));
        $this->assertSame('CUNO Fleetservices GmbH', $res->json('client.name'));
        $this->assertSame('info@cuno.gmbh', $res->json('client.email'));
        $this->assertSame('DE268871416', $res->json('client.vat_id'));
        $this->assertStringContainsString('Joachimsthaler Str. 19', (string) $res->json('client.address'));
        $this->assertStringContainsString('10719 Berlin', (string) $res->json('client.address'));
        $this->assertStringNotContainsString('Handelsregister', (string) $res->json('client.address'));
    }
}
