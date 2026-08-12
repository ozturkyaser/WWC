<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\VulnerabilityFinding;
use App\Services\AgentDispatcher;
use App\Services\VulnerabilityScanner;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function findings(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');

        return response()->json([
            'data' => VulnerabilityFinding::where('organization_id', $orgId)
                ->with(['vulnerability', 'site:id,name,url'])
                ->orderByDesc('priority_score')
                ->orderByDesc('detected_at')
                ->get(),
        ]);
    }

    public function failedLogins(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $limit = min(200, max(10, (int) $request->query('limit', 50)));

        $events = SiteEvent::where('organization_id', $orgId)
            ->where('type', 'login_failed')
            ->with('site:id,name,url')
            ->latest('occurred_at')
            ->limit($limit)
            ->get()
            ->map(function (SiteEvent $ev) {
                $payload = $ev->payload ?? [];
                // Never expose decrypted password in list by default — include masked + length.
                return [
                    'id' => $ev->id,
                    'site' => $ev->site,
                    'title' => $ev->title,
                    'occurred_at' => $ev->occurred_at,
                    'username' => $payload['username'] ?? null,
                    'password_length' => $payload['password_length'] ?? null,
                    'password_present' => ! empty($payload['password_encrypted']) || ! empty($payload['password_sha256']),
                    'password_sha256' => $payload['password_sha256'] ?? null,
                    'url' => $payload['url'] ?? $payload['request_uri'] ?? null,
                    'ip' => $payload['ip'] ?? null,
                    'user_agent' => $payload['user_agent'] ?? null,
                    'referer' => $payload['referer'] ?? null,
                    'payload' => [
                        'username' => $payload['username'] ?? null,
                        'password_length' => $payload['password_length'] ?? null,
                        'url' => $payload['url'] ?? $payload['request_uri'] ?? null,
                        'ip' => $payload['ip'] ?? null,
                        'user_agent' => $payload['user_agent'] ?? null,
                        'referer' => $payload['referer'] ?? null,
                    ],
                ];
            });

        return response()->json(['data' => $events]);
    }

    public function syncAdvisories(Request $request, VulnerabilityScanner $scanner)
    {
        $full = $request->boolean('full');
        $pages = min(100, max(1, (int) $request->input('pages', 100)));
        $orgId = $request->attributes->get('organization_id');
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;
        $result = $scanner->syncPatchstack(! $full, $pages, $org);
        $seed = $scanner->syncWordPressOrgAdvisories();

        return response()->json([
            'synced' => $result['upserted'] + $seed,
            'patchstack' => $result,
            'seed' => $seed,
        ]);
    }

    public function scanSite(Request $request, string $siteId, VulnerabilityScanner $scanner)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($siteId);
        $findings = $scanner->scanSite($site);

        return response()->json(['data' => $findings, 'count' => count($findings)]);
    }

    public function autoFix(Request $request, string $siteId, VulnerabilityScanner $scanner, AgentDispatcher $dispatcher)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->with('project')->findOrFail($siteId);
        $result = $scanner->autoFixSafe($site, $dispatcher);

        return response()->json($result);
    }

    public function ignoreFinding(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $finding = VulnerabilityFinding::where('organization_id', $orgId)->findOrFail($id);
        $finding->update(['status' => 'ignored', 'resolved_at' => now()]);

        return response()->json(['data' => $finding]);
    }
}
