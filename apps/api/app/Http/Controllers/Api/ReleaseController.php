<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ReleaseService;
use Illuminate\Http\Request;

class ReleaseController extends Controller
{
    public function show(ReleaseService $release)
    {
        return response()->json(['data' => $release->status()]);
    }

    public function deploy(Request $request, ReleaseService $release)
    {
        $data = $request->validate([
            'force' => 'sometimes|boolean',
        ]);
        $result = $release->deploy((bool) ($data['force'] ?? false));
        AuditLogger::log(
            'release.deploy',
            $request->attributes->get('organization_id'),
            $request->user(),
            null,
            ['ok' => $result['ok'], 'force' => (bool) ($data['force'] ?? false), 'message' => $result['message']],
            $request
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
