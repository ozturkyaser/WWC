<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\ContentStudioService;
use Illuminate\Http\Request;

/**
 * HTTP-MCP fuer Cursor/Agenten: dieselben Werkzeuge wie der Portal-KI-Editor.
 * Auth: Sanctum-Token. Aenderungen gehen immer zuerst in die Dev-Kopie.
 */
class McpController extends Controller
{
    public function tools()
    {
        return response()->json([
            'tools' => [
                [
                    'name' => 'wwc_site_scan',
                    'description' => 'Scannt Theme, Plugins, Editoren, Seiten und Branding in der isolierten Dev-Umgebung.',
                    'input' => ['site_id' => 'uuid'],
                ],
                [
                    'name' => 'wwc_content_plan',
                    'description' => 'Erzeugt einen Änderungsplan (Seiten, Blog, Logo, Texte) aus einer Anweisung.',
                    'input' => ['site_id' => 'uuid', 'prompt' => 'string'],
                ],
                [
                    'name' => 'wwc_apply_dev',
                    'description' => 'Wendet den letzten Plan nur in der isolierten Dev-Umgebung an.',
                    'input' => ['site_id' => 'uuid'],
                ],
                [
                    'name' => 'wwc_run_dev',
                    'description' => 'Plant den Auftrag und setzt ihn sofort in der isolierten Dev-Umgebung um. Liefert Ergebnis-URLs.',
                    'input' => ['site_id' => 'uuid', 'prompt' => 'string'],
                ],
                [
                    'name' => 'wwc_promote_live',
                    'description' => 'Übernimmt den in Dev geprüften Plan auf die Live-Site.',
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

        try {
            $result = match ($data['tool']) {
                'wwc_site_scan' => $studio->scan($site),
                'wwc_content_plan' => $studio->plan($site, (string) ($args['prompt'] ?? $request->input('prompt', ''))),
                'wwc_apply_dev' => $studio->applyDev($site),
                'wwc_run_dev' => $studio->runOnDev($site, (string) ($args['prompt'] ?? $request->input('prompt', ''))),
                'wwc_promote_live' => $studio->promoteLive($site),
            };
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'tool' => $data['tool'], 'data' => $result]);
    }
}
