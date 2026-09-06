<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\ContentStudioService;
use App\Services\WpMcpService;
use Illuminate\Http\Request;

/**
 * HTTP-MCP fuer Cursor/Agenten.
 * Content-Studio-Tools plus direkte WordPress-Steuerung über den gepaarten Agenten.
 */
class McpController extends Controller
{
    public function tools(WpMcpService $wpMcp)
    {
        return response()->json([
            'tools' => array_merge([
                [
                    'name' => 'wwc_site_scan',
                    'description' => 'Scannt Theme, Plugins, Editoren, Seiten und Branding. target: clone (isolierte Kopie) oder live (gepaarter Agent).',
                    'input' => ['site_id' => 'uuid', 'target' => 'clone|live'],
                ],
                [
                    'name' => 'wwc_content_plan',
                    'description' => 'Erzeugt einen Änderungsplan (Seiten, Plugins, CSS, Texte) aus einer Anweisung.',
                    'input' => ['site_id' => 'uuid', 'prompt' => 'string', 'target' => 'clone|live'],
                ],
                [
                    'name' => 'wwc_apply_dev',
                    'description' => 'Wendet den letzten Plan nur in der isolierten Kopie an.',
                    'input' => ['site_id' => 'uuid'],
                ],
                [
                    'name' => 'wwc_run_dev',
                    'description' => 'Plant den Auftrag und setzt ihn um. target clone = isolierte Kopie. target live braucht confirm_live=true.',
                    'input' => ['site_id' => 'uuid', 'prompt' => 'string', 'target' => 'clone|live', 'confirm_live' => 'bool'],
                ],
                [
                    'name' => 'wwc_promote_live',
                    'description' => 'Übernimmt den in der Kopie geprüften Plan auf die Live-Site über den gepaarten Agenten.',
                    'input' => ['site_id' => 'uuid'],
                ],
            ], $wpMcp->catalog()),
        ]);
    }

    public function call(Request $request, ContentStudioService $studio, WpMcpService $wpMcp)
    {
        $data = $request->validate([
            'tool' => 'required|string|in:wwc_site_scan,wwc_content_plan,wwc_apply_dev,wwc_run_dev,wwc_promote_live,wwc_sites,wwc_wp_tools,wwc_wp_call',
            'site_id' => 'nullable|uuid',
            'arguments' => 'nullable|array',
        ]);
        $orgId = $request->attributes->get('organization_id');
        $args = $data['arguments'] ?? [];
        $siteId = $data['site_id'] ?? ($args['site_id'] ?? null);

        try {
            if ($data['tool'] === 'wwc_sites') {
                $sites = Site::query()->where('organization_id', $orgId)->orderBy('name')->get();

                return response()->json([
                    'ok' => true,
                    'tool' => 'wwc_sites',
                    'data' => $wpMcp->listSites($sites),
                ]);
            }

            if (! is_string($siteId) || $siteId === '') {
                return response()->json(['ok' => false, 'message' => 'site_id fehlt.'], 422);
            }
            $site = Site::where('organization_id', $orgId)->findOrFail($siteId);
            $target = isset($args['target']) ? (string) $args['target'] : null;

            $result = match ($data['tool']) {
                'wwc_site_scan' => $studio->scan($site, $target),
                'wwc_content_plan' => $studio->plan($site, (string) ($args['prompt'] ?? $request->input('prompt', '')), $target),
                'wwc_apply_dev' => $studio->applyDev($site),
                'wwc_run_dev' => $studio->run(
                    $site,
                    (string) ($args['prompt'] ?? $request->input('prompt', '')),
                    $target ?? 'clone',
                    (bool) ($args['confirm_live'] ?? false)
                ),
                'wwc_promote_live' => $studio->promoteLive($site),
                'wwc_wp_tools' => $wpMcp->wpTools($site, $target),
                'wwc_wp_call' => $wpMcp->wpCall(
                    $site,
                    (string) ($args['tool'] ?? ''),
                    is_array($args['arguments'] ?? null) ? $args['arguments'] : [],
                    $target,
                    (bool) ($args['confirm_live'] ?? false)
                ),
            };
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'tool' => $data['tool'], 'data' => $result]);
    }
}
