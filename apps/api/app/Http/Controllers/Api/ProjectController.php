<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\PairingService;
use App\Services\ProjectPurgeService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');

        return response()->json([
            'data' => Project::where('organization_id', $orgId)
                ->with(['client:id,name,email', 'sites:id,project_id,name,status,url,onboarding_status'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, PairingService $pairing)
    {
        $orgId = $request->attributes->get('organization_id');
        $data = $request->validate([
            'client_id' => 'required|uuid',
            'name' => 'required|string|max:160',
            'scope' => 'nullable|array',
            'monthly_budget_cents' => 'required|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'active' => 'nullable|boolean',
            'site_name' => 'nullable|string|max:160',
            'site_url' => 'nullable|url',
        ]);

        $project = Project::create([
            'organization_id' => $orgId,
            'client_id' => $data['client_id'],
            'name' => $data['name'],
            'scope' => array_merge(Project::DEFAULT_SCOPE, $data['scope'] ?? []),
            'monthly_budget_cents' => $data['monthly_budget_cents'],
            'currency' => $data['currency'] ?? 'EUR',
            'active' => $data['active'] ?? true,
        ]);

        $response = [
            'data' => $project->load(['client:id,name', 'sites']),
            'plugin_download_url' => url('/api/plugin/download'),
            'api_url' => rtrim((string) config('wwc.public_api_url', config('app.url')), '/'),
            'install' => null,
        ];

        if (! empty($data['site_url'])) {
            $site = Site::create([
                'organization_id' => $orgId,
                'client_id' => $data['client_id'],
                'project_id' => $project->id,
                'name' => $data['site_name'] ?: $data['name'],
                'url' => rtrim($data['site_url'], '/'),
                'status' => 'pending',
            ]);

            $code = $pairing->createCode($site);
            AuditLogger::log('site.created', $orgId, $request->user(), $site->id, [
                'via' => 'project.create',
            ], $request);

            $response['data'] = $project->fresh()->load(['client:id,name', 'sites']);
            $response['install'] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_url' => $site->url,
                'pairing_code' => $code->code,
                'expires_at' => $code->expires_at,
                'plugin_download_url' => url('/api/plugin/download'),
                'api_url' => rtrim((string) config('wwc.public_api_url', config('app.url')), '/'),
                'steps' => [
                    'Plugin-ZIP herunterladen',
                    'In WordPress unter Plugins → Installieren → Plugin hochladen das ZIP wählen und aktivieren',
                    'Einstellungen → WWC Agent öffnen',
                    'API-URL und Pairing-Code eintragen → Verbinden',
                ],
            ];
        }

        return response()->json($response, 201);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $project = Project::where('organization_id', $orgId)
            ->with(['client', 'sites'])
            ->findOrFail($id);

        return response()->json(['data' => $project]);
    }

    public function update(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $project = Project::where('organization_id', $orgId)->findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:160',
            'scope' => 'sometimes|array',
            'monthly_budget_cents' => 'sometimes|integer|min:0',
            'currency' => 'sometimes|string|size:3',
            'active' => 'sometimes|boolean',
        ]);

        if (isset($data['scope'])) {
            $data['scope'] = array_merge(Project::DEFAULT_SCOPE, $project->scope ?? [], $data['scope']);
        }

        $project->update($data);

        return response()->json(['data' => $project->fresh()]);
    }

    public function destroy(Request $request, string $id, ProjectPurgeService $purge)
    {
        $orgId = $request->attributes->get('organization_id');
        $project = Project::where('organization_id', $orgId)->with('sites')->findOrFail($id);

        $result = $purge->destroyCompletely($project);

        AuditLogger::log('project.deleted', $orgId, $request->user(), null, [
            'project_id' => $result['project_id'],
            'name' => $result['project_name'],
            'invoices_deleted' => $result['invoices_deleted'],
            'remote' => $result['remote'],
        ], $request);

        return response()->json($result);
    }

    public function reconnect(Request $request, string $id, PairingService $pairing)
    {
        $orgId = $request->attributes->get('organization_id');
        $project = Project::where('organization_id', $orgId)->with('sites')->findOrFail($id);
        $site = $project->sites->first();

        if (! $site) {
            $data = $request->validate([
                'site_name' => 'required|string|max:160',
                'site_url' => 'required|url',
            ]);
            $site = Site::create([
                'organization_id' => $orgId,
                'client_id' => $project->client_id,
                'project_id' => $project->id,
                'name' => $data['site_name'],
                'url' => rtrim($data['site_url'], '/'),
                'status' => 'pending',
            ]);
        }

        $result = $pairing->reconnect($site);

        return response()->json([
            'data' => $project->fresh()->load(['client:id,name', 'sites']),
            'install' => [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_url' => $site->url,
                'pairing_code' => $result['pairing_code'],
                'expires_at' => $result['expires_at'],
                'plugin_download_url' => $result['plugin_download_url'],
                'api_url' => $result['api_url'],
                'steps' => [
                    'Plugin-ZIP herunterladen (falls nötig)',
                    'In WP: WWC Agent → Verbindung trennen',
                    'API-URL + neuen Pairing-Code eintragen → Verbinden',
                ],
            ],
        ]);
    }
}
