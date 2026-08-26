<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\ContentStudioService;
use Illuminate\Http\Request;

/**
 * HTTP-MCP fuer Cursor/Agenten: dieselben Werkzeuge wie der Portal-KI-Editor.
 * Live und isolierte Kopie teilen denselben Site-Datensatz, nicht denselben Pairing-Key.
 */
class McpController extends Controller
{
    public function tools()
    {
        return response()->json([
            'tools' => [
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
            ],
        ]);
    }

    public function call(Request $request, ContentStudioService $studio)
    {
        $data = $request->validate([
            'tool' => 'required|string|in:wwc_site_scan,wwc_content_plan,wwc_apply_dev,wwc_run_dev,wwc_promote_live',
            'site_id' => 'required|uuid',
            'arguments' => 'nullable|array',
        ]);
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($data['site_id']);
        $args = $data['arguments'] ?? [];
        $target = isset($args['target']) ? (string) $args['target'] : null;

        try {
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
            };
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'tool' => $data['tool'], 'data' => $result]);
    }
}
