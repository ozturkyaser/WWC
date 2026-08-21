<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Site;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\CatalogService;
use App\Services\ImpressumParserService;
use App\Services\PairingService;
use App\Services\SiteOnboardingService;
use App\Services\StagingPortalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnboardingController extends Controller
{
    public function tiers(Request $request, CatalogService $catalog)
    {
        $orgId = $request->attributes->get('organization_id');
        $org = Organization::findOrFail($orgId);
        $tiers = collect($catalog->maintenanceTiers($org))->map(function ($tier) {
            return [
                'key' => $tier['key'],
                'label' => $tier['label'],
                'description' => $tier['description'] ?? '',
                'monthly_cents' => $tier['monthly_cents'],
                'monthly_eur' => $tier['monthly_cents'] !== null ? round($tier['monthly_cents'] / 100, 2) : null,
                'hours_included' => $tier['hours_included'] ?? ($tier['scope']['hours_included'] ?? 0),
                'scope' => $tier['scope'] ?? [],
            ];
        });

        return response()->json([
            'data' => $tiers,
            'hour_packages' => $catalog->hourPackages($org, true),
        ]);
    }

    public function analyzeImpressum(Request $request, ImpressumParserService $parser)
    {
        $data = $request->validate([
            'site_url' => 'required|string|max:500',
        ]);

        $result = $parser->analyze($data['site_url']);

        // Always 200 so the wizard can continue with a draft client even if Impressum is incomplete.
        return response()->json($result);
    }

    public function createProject(
        Request $request,
        PairingService $pairing,
        SiteOnboardingService $onboarding,
        CatalogService $catalog
    ) {
        $orgId = $request->attributes->get('organization_id');
        $org = Organization::findOrFail($orgId);
        $data = $request->validate([
            'site_url' => 'required|url',
            'site_name' => 'nullable|string|max:160',
            'project_name' => 'nullable|string|max:160',
            'maintenance_tier' => 'required|string|in:1,2,3,custom',
            'monthly_budget_cents' => 'nullable|integer|min:0',
            'hour_package_id' => 'nullable|string|max:64',
            'client' => 'required|array',
            'client.name' => 'required|string|max:160',
            'client.email' => 'nullable|email',
            'client.company' => 'nullable|string|max:160',
            'client.address' => 'nullable|string',
            'client.vat_id' => 'nullable|string|max:64',
            'client_id' => 'nullable|uuid',
        ]);

        $tiers = collect($catalog->maintenanceTiers($org))->keyBy('key');
        $tier = $tiers->get($data['maintenance_tier']);
        if (! $tier) {
            return response()->json(['message' => 'Unbekannte Wartungsstufe'], 422);
        }

        $budget = $data['maintenance_tier'] === 'custom'
            ? (int) ($data['monthly_budget_cents'] ?? 0)
            : (int) ($tier['monthly_cents'] ?? 0);

        if ($budget <= 0) {
            return response()->json(['message' => 'Monatspreis erforderlich'], 422);
        }

        $scope = array_merge(Project::DEFAULT_SCOPE, $tier['scope'] ?? []);
        if (! empty($data['hour_package_id'])) {
            $pkg = collect($catalog->hourPackages($org, true))
                ->firstWhere('id', $data['hour_package_id']);
            if ($pkg) {
                $scope['hours_included'] = (float) ($scope['hours_included'] ?? 0) + (float) $pkg['hours'];
                $scope['hour_package_id'] = $pkg['id'];
                $scope['hour_package_name'] = $pkg['name'];
                $scope['hour_package_hours'] = (float) $pkg['hours'];
            }
        }

        return DB::transaction(function () use ($request, $pairing, $onboarding, $orgId, $data, $scope, $budget) {
            if (! empty($data['client_id'])) {
                $client = Client::where('organization_id', $orgId)->findOrFail($data['client_id']);
                $client->fill(array_filter([
                    'name' => $data['client']['name'] ?? null,
                    'email' => $data['client']['email'] ?? null,
                    'company' => $data['client']['company'] ?? null,
                    'address' => $data['client']['address'] ?? null,
                    'vat_id' => $data['client']['vat_id'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''))->save();
            } else {
                $client = Client::create([
                    'organization_id' => $orgId,
                    'name' => $data['client']['name'],
                    'email' => $data['client']['email'] ?? null,
                    'company' => $data['client']['company'] ?? null,
                    'address' => $data['client']['address'] ?? null,
                    'vat_id' => $data['client']['vat_id'] ?? null,
                ]);
            }

            $host = parse_url($data['site_url'], PHP_URL_HOST) ?: $data['site_url'];
            $projectName = trim((string) ($data['project_name'] ?? '')) ?: ($client->name.' – '.$host);
            $siteName = trim((string) ($data['site_name'] ?? '')) ?: $host;

            $project = Project::create([
                'organization_id' => $orgId,
                'client_id' => $client->id,
                'name' => $projectName,
                'scope' => $scope,
                'monthly_budget_cents' => $budget,
                'maintenance_tier' => $data['maintenance_tier'],
                'currency' => 'EUR',
                'active' => true,
            ]);

            $site = Site::create([
                'organization_id' => $orgId,
                'client_id' => $client->id,
                'project_id' => $project->id,
                'name' => $siteName,
                'url' => rtrim($data['site_url'], '/'),
                'status' => 'pending',
            ]);

            $onboarding->markAwaitingPair($site);
            $code = $pairing->createCode($site);

            AuditLogger::log('project.onboarded', $orgId, $request->user(), $site->id, [
                'project_id' => $project->id,
                'tier' => $data['maintenance_tier'],
                'budget_cents' => $budget,
            ], $request);

            return response()->json([
                'data' => $project->load(['client', 'sites']),
                'client' => $client,
                'install' => [
                    'site_id' => $site->id,
                    'site_name' => $site->name,
                    'site_url' => $site->url,
                    'pairing_code' => $code->code,
                    'expires_at' => $code->expires_at,
                    'plugin_download_url' => url('/api/plugin/download'),
                    'api_url' => rtrim((string) config('wwc.public_api_url', config('app.url')), '/'),
                    'steps' => [
                        'Plugin-ZIP herunterladen und in WordPress installieren/aktivieren',
                        'Einstellungen → WWC Agent: API-URL bleibt https://wwc.kiservicehub.de, Pairing-Code → Verbinden',
                        'Danach startet automatisch: Full-Backup → Development-Umgebung',
                    ],
                ],
                'onboarding' => $onboarding->statusPayload($site->fresh()),
            ], 201);
        });
    }

    public function siteStatus(Request $request, string $siteId, SiteOnboardingService $onboarding, StagingPortalService $staging)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($siteId);

        return response()->json([
            'data' => [
                'site' => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'url' => $site->url,
                    'status' => $site->status,
                    'paired_at' => $site->paired_at,
                ],
                'onboarding' => $onboarding->statusPayload($site, $staging),
            ],
        ]);
    }
}
